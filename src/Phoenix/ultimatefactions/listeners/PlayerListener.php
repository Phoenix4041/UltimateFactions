<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\listeners;

use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Trapdoor;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\objects\Claim;
use Phoenix\ultimatefactions\objects\Faction;
use Phoenix\ultimatefactions\objects\FactionPlayer;

class PlayerListener implements Listener {
    
    private Main $plugin;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Check if player exists in the system
        if (!$this->plugin->getPlayerManager()->playerExists($playerName)) {
            // Create new player
            $this->plugin->getPlayerManager()->createPlayer($playerName);
            
            $this->plugin->getLogger()->info("Created new faction player: " . $playerName);
        } else {
            // Validate existing player's faction
            $factionPlayer = $this->plugin->getPlayerManager()->getPlayerByName($playerName);
            
            if ($factionPlayer->hasFaction()) {
                $faction = $this->plugin->getFactionManager()->getFactionByName($factionPlayer->getFactionName());
                
                // If faction doesn't exist or player is not in faction, remove them
                if ($faction === null || !$faction->isMember($playerName)) {
                    $factionPlayer->leaveFaction();
                    $this->plugin->getPlayerManager()->updatePlayer($factionPlayer);
                    
                    $message = $this->plugin->getMessageManager()->getMessage("faction_auto_leave");
                    $player->sendMessage($message);
                }
            }
        }
        
        // Update last seen time
        $this->plugin->getPlayerManager()->updateLastSeen($playerName);
    }
    
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Update last seen time
        $this->plugin->getPlayerManager()->updateLastSeen($playerName);
        
        // Remove from chunk border list
        $this->plugin->removePlayerFromBorderList($playerName);
        
        // Clear any cooldowns (optional, depends on implementation)
        $this->plugin->getCooldownManager()->clearPlayerCooldowns($playerName);
    }
    
    public function onPlayerMove(PlayerMoveEvent $event): void {
        if ($event->isCancelled()) {
            return;
        }
        
        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();
        
        // Check if world allows faction interactions
        if (!$this->isValidFactionWorld($to->getWorld()->getFolderName())) {
            return;
        }
        
        // Calculate chunk coordinates
        $fromChunkX = $from->getFloorX() >> Chunk::COORD_BIT_SIZE;
        $fromChunkZ = $from->getFloorZ() >> Chunk::COORD_BIT_SIZE;
        $toChunkX = $to->getFloorX() >> Chunk::COORD_BIT_SIZE;
        $toChunkZ = $to->getFloorZ() >> Chunk::COORD_BIT_SIZE;
        
        // Only check if player moved to a different chunk
        if ($fromChunkX === $toChunkX && $fromChunkZ === $toChunkZ) {
            return;
        }
        
        $worldName = $to->getWorld()->getFolderName();
        $oldClaim = $this->plugin->getClaimManager()->getClaimAt($fromChunkX, $fromChunkZ, $worldName);
        $newClaim = $this->plugin->getClaimManager()->getClaimAt($toChunkX, $toChunkZ, $worldName);
        
        // Only notify if claims are different
        if ($this->areClaimsDifferent($oldClaim, $newClaim)) {
            $this->sendTerritoryMessage($player, $oldClaim, $newClaim);
        }
    }
    
    private function areClaimsDifferent(?Claim $oldClaim, ?Claim $newClaim): bool {
        if ($oldClaim === null && $newClaim === null) {
            return false;
        }
        
        if ($oldClaim === null || $newClaim === null) {
            return true;
        }
        
        return $oldClaim->getFactionName() !== $newClaim->getFactionName();
    }
    
    private function sendTerritoryMessage(Player $player, ?Claim $oldClaim, ?Claim $newClaim): void {
        $playerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($player);
        
        // Determine old territory name
        $oldTerritoryName = $oldClaim ? $oldClaim->getFactionName() : "Wilderness";
        
        // Determine new territory info
        if ($newClaim === null) {
            $newTerritoryName = "Wilderness";
            $color = "§f"; // White
        } else {
            $newTerritoryName = $newClaim->getFactionName();
            $color = $this->getTerritoryColor($player, $newClaim, $playerFaction);
        }
        
        // Send territory change message
        $message = $this->plugin->getMessageManager()->getMessage("territory_enter", [
            "{OLD_TERRITORY}" => $oldTerritoryName,
            "{NEW_TERRITORY}" => $color . $newTerritoryName
        ]);
        
        $player->sendMessage($message);
    }
    
    private function getTerritoryColor(Player $player, Claim $claim, ?Faction $playerFaction): string {
        if ($playerFaction === null) {
            return "§c"; // Red for enemies
        }
        
        $claimFactionName = $claim->getFactionName();
        
        // Own faction
        if ($playerFaction->getName() === $claimFactionName) {
            return "§a"; // Green
        }
        
        // Allied faction
        if ($playerFaction->isAlly($claimFactionName)) {
            return "§b"; // Aqua
        }
        
        // Enemy faction
        return "§c"; // Red
    }
    
    public function onPlayerInteract(PlayerInteractEvent $event): void {
        if ($event->isCancelled()) {
            return;
        }
        
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $item = $event->getItem();
        $world = $block->getPosition()->getWorld();
        
        // Check if world allows faction interactions
        if (!$this->isValidFactionWorld($world->getFolderName())) {
            return;
        }
        
        // Check for admin bypass
        if ($this->hasAdminBypass($player)) {
            return;
        }
        
        $chunkX = $block->getPosition()->getFloorX() >> Chunk::COORD_BIT_SIZE;
        $chunkZ = $block->getPosition()->getFloorZ() >> Chunk::COORD_BIT_SIZE;
        
        $claim = $this->plugin->getClaimManager()->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
        
        if ($claim === null) {
            return; // Wilderness - allow interaction
        }
        
        $claimFaction = $this->plugin->getFactionManager()->getFactionByName($claim->getFactionName());
        if ($claimFaction === null) {
            return; // Invalid faction - allow interaction
        }
        
        $playerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($player);
        
        // Allow if player is in the same faction
        if ($playerFaction !== null && $playerFaction->getName() === $claimFaction->getName()) {
            return;
        }
        
        // Allow if factions are allied
        if ($playerFaction !== null && $claimFaction->isAlly($playerFaction->getName())) {
            return;
        }
        
        // Allow if faction is being raided (not frozen)
        if (!$claimFaction->isFreeze()) {
            return;
        }
        
        // Check for protected interactions
        if ($this->isProtectedInteraction($block, $item)) {
            $event->cancel();
            
            if (!$this->plugin->getCooldownManager()->hasCooldown($player->getName(), "interact_message")) {
                $message = $this->plugin->getMessageManager()->getMessage("interaction_protected", [
                    "{FACTION}" => $claimFaction->getName()
                ]);
                $player->sendMessage($message);
                
                $this->plugin->getCooldownManager()->setCooldown(
                    $player->getName(), 
                    "interact_message", 
                    3 // 3 seconds
                );
            }
        }
    }
    
    private function isProtectedInteraction($block, $item): bool {
        // Protected blocks
        $protectedBlocks = [
            VanillaBlocks::CHEST(),
            VanillaBlocks::TRAPPED_CHEST(),
            VanillaBlocks::SHULKER_BOX(),
            VanillaBlocks::BARREL(),
            VanillaBlocks::FURNACE(),
            VanillaBlocks::BLAST_FURNACE(),
            VanillaBlocks::SMOKER(),
            VanillaBlocks::BREWING_STAND(),
            VanillaBlocks::ANVIL(),
            VanillaBlocks::ENCHANTING_TABLE()
        ];
        
        foreach ($protectedBlocks as $protectedBlock) {
            if ($block->isSameType($protectedBlock)) {
                return true;
            }
        }
        
        // Protected doors/gates
        if ($block instanceof Door || $block instanceof FenceGate || $block instanceof Trapdoor) {
            return true;
        }
        
        // Protected items
        $protectedItems = [
            VanillaItems::BUCKET(),
            VanillaItems::WATER_BUCKET(),
            VanillaItems::LAVA_BUCKET(),
            VanillaItems::FLINT_AND_STEEL(),
            VanillaItems::DIAMOND_HOE(),
            VanillaItems::GOLDEN_HOE(),
            VanillaItems::IRON_HOE(),
            VanillaItems::STONE_HOE(),
            VanillaItems::WOODEN_HOE(),
            VanillaItems::DIAMOND_SHOVEL(),
            VanillaItems::GOLDEN_SHOVEL(),
            VanillaItems::IRON_SHOVEL(),
            VanillaItems::STONE_SHOVEL(),
            VanillaItems::WOODEN_SHOVEL()
        ];
        
        foreach ($protectedItems as $protectedItem) {
            if ($item->equals($protectedItem, false, false)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function onPlayerChat(PlayerChatEvent $event): void {
        if ($event->isCancelled()) {
            return;
        }
        
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        $factionPlayer = $this->plugin->getPlayerManager()->getPlayerByName($playerName);
        if ($factionPlayer === null || !$factionPlayer->hasFaction()) {
            return;
        }
        
        // Check if player has faction chat enabled
        if (!$this->plugin->getPlayerManager()->hasFactionChatEnabled($playerName)) {
            return;
        }
        
        $faction = $this->plugin->getFactionManager()->getFactionByName($factionPlayer->getFactionName());
        if ($faction === null) {
            return;
        }
        
        // Cancel the original message
        $event->cancel();
        
        // Send faction chat message
        $message = $this->plugin->getMessageManager()->getMessage("faction_chat_format", [
            "{FACTION}" => $faction->getName(),
            "{ROLE}" => ucfirst($factionPlayer->getRole()),
            "{PLAYER}" => $playerName,
            "{MESSAGE}" => $event->getMessage()
        ]);
        
        // Broadcast to faction members
        $onlineMembers = $this->plugin->getPlayerManager()->getOnlinePlayersByFaction($faction->getName());
        foreach ($onlineMembers as $member) {
            $member->sendMessage($message);
        }
        
        // Log faction chat
        $this->plugin->getLogger()->info("[FACTION CHAT] " . $faction->getName() . " - " . $playerName . ": " . $event->getMessage());
    }
    
    /**
     * Check if the world allows faction interactions
     */
    private function isValidFactionWorld(string $worldName): bool {
        $factionWorlds = $this->plugin->getConfigManager()->getFactionWorlds();
        return empty($factionWorlds) || in_array($worldName, $factionWorlds, true);
    }
    
    /**
     * Check if player has admin bypass permissions
     */
    private function hasAdminBypass(Player $player): bool {
        return $player->hasPermission("ultimatefactions.bypass");
    }
}