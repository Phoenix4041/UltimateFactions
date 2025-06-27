<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\listeners;

use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\objects\Claim;
use Phoenix\ultimatefactions\objects\Faction;

class BlockListener implements Listener {
    
    private Main $plugin;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function onBlockBreak(BlockBreakEvent $event): void {
        if ($event->isCancelled()) {
            return;
        }
        
        $player = $event->getPlayer();
        $block = $event->getBlock();
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
            return; // Wilderness - allow break
        }
        
        $claimFaction = $this->plugin->getFactionManager()->getFactionByName($claim->getFactionName());
        if ($claimFaction === null) {
            return; // Invalid faction - allow break
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
        
        // Block is protected - cancel event and send message
        $event->cancel();
        
        $message = $this->plugin->getMessageManager()->getMessage("block_break_protected", [
            "{FACTION}" => $claimFaction->getName()
        ]);
        $player->sendMessage($message);
        
        // Apply cooldown to prevent spam
        $this->plugin->getCooldownManager()->setCooldown(
            $player->getName(), 
            "block_break_message", 
            3 // 3 seconds
        );
    }
    
    public function onBlockPlace(BlockPlaceEvent $event): void {
        if ($event->isCancelled()) {
            return;
        }
        
        $player = $event->getPlayer();
        $transaction = $event->getTransaction();
        
        foreach ($transaction->getBlocks() as [$x, $y, $z, $block]) {
            if (!$block instanceof Block) {
                continue;
            }
            
            $world = $block->getPosition()->getWorld();
            
            // Check if world allows faction interactions
            if (!$this->isValidFactionWorld($world->getFolderName())) {
                continue;
            }
            
            // Check for admin bypass
            if ($this->hasAdminBypass($player)) {
                continue;
            }
            
            $chunkX = $block->getPosition()->getFloorX() >> Chunk::COORD_BIT_SIZE;
            $chunkZ = $block->getPosition()->getFloorZ() >> Chunk::COORD_BIT_SIZE;
            
            $claim = $this->plugin->getClaimManager()->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
            
            if ($claim === null) {
                continue; // Wilderness - allow place
            }
            
            $claimFaction = $this->plugin->getFactionManager()->getFactionByName($claim->getFactionName());
            if ($claimFaction === null) {
                continue; // Invalid faction - allow place
            }
            
            $playerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($player);
            
            // Allow if player is in the same faction
            if ($playerFaction !== null && $playerFaction->getName() === $claimFaction->getName()) {
                continue;
            }
            
            // Allow if factions are allied
            if ($playerFaction !== null && $claimFaction->isAlly($playerFaction->getName())) {
                continue;
            }
            
            // Allow if faction is being raided (not frozen)
            if (!$claimFaction->isFreeze()) {
                continue;
            }
            
            // Block placement is protected - cancel event and send message
            $event->cancel();
            
            if (!$this->plugin->getCooldownManager()->hasCooldown($player->getName(), "block_place_message")) {
                $message = $this->plugin->getMessageManager()->getMessage("block_place_protected", [
                    "{FACTION}" => $claimFaction->getName()
                ]);
                $player->sendMessage($message);
                
                // Apply cooldown to prevent spam
                $this->plugin->getCooldownManager()->setCooldown(
                    $player->getName(), 
                    "block_place_message", 
                    3 // 3 seconds
                );
            }
            
            return; // Cancel entire transaction
        }
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