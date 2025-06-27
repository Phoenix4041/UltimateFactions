<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\listeners;

use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\antiglitch\AntiGlitch;

/**
 * AntiGlitch Listener for UltimateFactions
 * Handles movement and teleportation events to prevent glitching
 * into protected faction territories
 */
class AntiGlitchListener implements Listener {
    
    private Main $plugin;
    private AntiGlitch $antiGlitch;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->antiGlitch = AntiGlitch::getInstance();
}
    
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        
        // Initialize safe position for new player
        $this->antiGlitch->setLastSafePosition($player);
        
        // Apply appropriate game mode based on current position
        $this->updatePlayerGameMode($player);
    }
    
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        
        // Clean up player data from AntiGlitch system
        $this->antiGlitch->removePlayerData($player);
        
        // Remove from chunk border display
        $this->plugin->removePlayerFromBorderList($player->getName());
    }
    
    public function onEntityTeleport(EntityTeleportEvent $event): void {
        $entity = $event->getEntity();
        
        if (!$entity instanceof Player) {
            return;
        }
        
        $player = $entity;
        $to = $event->getTo();
        $from = $event->getFrom();
        
        // Skip if player has bypass permissions
        if ($this->antiGlitch->hasBypass($player)) {
            $this->antiGlitch->setLastSafePosition($player);
            return;
        }
        
        // Skip for creative/spectator players
        if ($player->getGamemode()->equals(GameMode::CREATIVE()) || 
            $player->getGamemode()->equals(GameMode::SPECTATOR())) {
            return;
        }
        
        // Check teleport cooldown
        if (!$this->antiGlitch->canTeleport($player)) {
            $remaining = $this->antiGlitch->getTeleportCooldown($player);
            $message = $this->plugin->getMessageManager()->getMessage("antiglitch_teleport_cooldown", [
                "{time}" => $remaining
            ]);
            $player->sendMessage($message);
            $event->cancel();
            return;
        }
        
        // Validate destination
        if (!$this->antiGlitch->validateMovement($player, $from, $to)) {
            $event->cancel();
            
            // Try to teleport to safe position
            $this->plugin->getScheduler()->scheduleDelayedTask(
                new \pocketmine\scheduler\ClosureTask(function() use ($player): void {
                    if ($player->isOnline()) {
                        $this->antiGlitch->teleportToSafePosition($player);
                    }
                }),
                1 // 1 tick delay
            );
            
            // Send message about blocked teleport
            $message = $this->plugin->getMessageManager()->getMessage("antiglitch_teleport_blocked");
            $player->sendMessage($message);
            
            return;
        }
        
        // Set teleport cooldown for valid teleports
        $this->antiGlitch->setTeleportCooldown($player, 3);
        
        // Update game mode after teleport
        $this->plugin->getScheduler()->scheduleDelayedTask(
            new \pocketmine\scheduler\ClosureTask(function() use ($player): void {
                if ($player->isOnline()) {
                    $this->updatePlayerGameMode($player);
                }
            }),
            2 // 2 tick delay to ensure teleport is complete
        );
    }
    
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $to = $event->getTo();
        $from = $event->getFrom();
        
        // Skip if player has bypass permissions
        if ($this->antiGlitch->hasBypass($player)) {
            $this->antiGlitch->setLastSafePosition($player);
            $this->updatePlayerGameMode($player);
            return;
        }
        
        // Skip for creative/spectator players
        if ($player->getGamemode()->equals(GameMode::CREATIVE()) || 
            $player->getGamemode()->equals(GameMode::SPECTATOR())) {
            return;
        }
        
        // Check if player is moving to a different chunk
        $fromChunkX = $from->getFloorX() >> 4;
        $fromChunkZ = $from->getFloorZ() >> 4;
        $toChunkX = $to->getFloorX() >> 4;
        $toChunkZ = $to->getFloorZ() >> 4;
        
        // Only validate if moving to different chunk
        if ($fromChunkX !== $toChunkX || $fromChunkZ !== $toChunkZ) {
            if (!$this->antiGlitch->validateMovement($player, $from, $to)) {
                $event->cancel();
                
                // Send message about restricted area
                $message = $this->plugin->getMessageManager()->getMessage("antiglitch_movement_blocked");
                $player->sendMessage($message);
                
                return;
            }
        }
        
        // Update game mode if needed
        $this->updatePlayerGameMode($player);
    }
    
    /**
     * Update player game mode based on their current location
     */
    private function updatePlayerGameMode(Player $player): void {
        // Skip for creative/spectator players
        if ($player->getGamemode()->equals(GameMode::CREATIVE()) || 
            $player->getGamemode()->equals(GameMode::SPECTATOR())) {
            return;
        }
        
        $position = $player->getPosition();
        $world = $position->getWorld();
        
        if ($world === null) {
            return;
        }
        
        $chunkX = $position->getFloorX() >> 4;
        $chunkZ = $position->getFloorZ() >> 4;
        
        $claim = $this->plugin->getClaimManager()->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
        
        // In wilderness - ensure survival mode
        if ($claim === null) {
            if ($player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::SURVIVAL());
            }
            return;
        }
        
        $playerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($player);
        $claimFaction = $claim->getFaction();
        
        if ($claimFaction === null) {
            if ($player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::SURVIVAL());
            }
            return;
        }
        
        // During raid - allow survival mode
        if ($claimFaction->isRaid()) {
            if ($player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::SURVIVAL());
            }
            return;
        }
        
        // Player has no faction - set adventure mode in claimed areas
        if ($playerFaction === null) {
            if (!$player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::ADVENTURE());
                
                $message = $this->plugin->getMessageManager()->getMessage("antiglitch_adventure_mode");
                $player->sendMessage($message);
            }
            return;
        }
        
        // Own faction territory - ensure survival mode
        if ($playerFaction->getName() === $claimFaction->getName()) {
            if ($player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::SURVIVAL());
            }
            return;
        }
        
        // Allied territory - ensure survival mode
        if ($playerFaction->isAlly($claimFaction->getName())) {
            if ($player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::SURVIVAL());
            }
            return;
        }
        
        // Enemy territory with freeze protection - set adventure mode
        if ($claimFaction->isFreeze()) {
            if (!$player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::ADVENTURE());
                
                $message = $this->plugin->getMessageManager()->getMessage("antiglitch_raid_protection");
                $player->sendMessage($message);
            }
            return;
        }
        
        // Check power levels for raiding
        $attackerPower = $playerFaction->getPower();
        $defenderPower = $claimFaction->getPower();
        
        // If attacker has more power, allow survival mode
        if ($attackerPower > $defenderPower) {
            if ($player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::SURVIVAL());
            }
        } else {
            // Not enough power to raid - set adventure mode
            if (!$player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->setGamemode(GameMode::ADVENTURE());
                
                $message = $this->plugin->getMessageManager()->getMessage("antiglitch_insufficient_power");
                $player->sendMessage($message);
            }
        }
    }