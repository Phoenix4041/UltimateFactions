<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\antiglitch;

use pocketmine\player\Player;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\Position;
use Phoenix\ultimatefactions\Main;

/**
 * AntiGlitch System for UltimateFactions
 * Prevents players from exploiting teleportation and movement glitches
 * in claimed territories and during faction raids
 */
class AntiGlitch {
    
    use SingletonTrait;
    
    /** @var array<string, Position> */
    private array $lastPositions = [];
    
    /** @var array<string, bool> */
    private array $bypassPlayers = [];
    
    /** @var array<string, int> */
    private array $teleportCooldowns = [];
    
    private Main $plugin;
    
    public function __construct() {
        $this->plugin = Main::getInstance();
    }
    
    /**
     * Set the last safe position for a player
     */
    public function setLastSafePosition(Player $player): void {
        $this->lastPositions[strtolower($player->getName())] = clone $player->getPosition();
    }
    
    /**
     * Remove stored position data for a player
     */
    public function removePlayerData(Player $player): void {
        $playerName = strtolower($player->getName());
        
        if (isset($this->lastPositions[$playerName])) {
            unset($this->lastPositions[$playerName]);
        }
        
        if (isset($this->bypassPlayers[$playerName])) {
            unset($this->bypassPlayers[$playerName]);
        }
        
        if (isset($this->teleportCooldowns[$playerName])) {
            unset($this->teleportCooldowns[$playerName]);
        }
    }
    
    /**
     * Get the last safe position for a player
     */
    public function getLastSafePosition(Player $player): ?Position {
        return $this->lastPositions[strtolower($player->getName())] ?? null;
    }
    
    /**
     * Check if player has bypass permissions
     */
    public function hasBypass(Player $player): bool {
        return $player->hasPermission("ultimatefactions.bypass") || 
               $this->bypassPlayers[strtolower($player->getName())] ?? false;
    }
    
    /**
     * Set bypass status for a player
     */
    public function setBypass(Player $player, bool $bypass): void {
        $this->bypassPlayers[strtolower($player->getName())] = $bypass;
    }
    
    /**
     * Check if player can teleport (cooldown check)
     */
    public function canTeleport(Player $player): bool {
        $playerName = strtolower($player->getName());
        $currentTime = time();
        
        if (!isset($this->teleportCooldowns[$playerName])) {
            return true;
        }
        
        return $currentTime >= $this->teleportCooldowns[$playerName];
    }
    
    /**
     * Set teleport cooldown for player
     */
    public function setTeleportCooldown(Player $player, int $seconds = 5): void {
        $this->teleportCooldowns[strtolower($player->getName())] = time() + $seconds;
    }
    
    /**
     * Get remaining teleport cooldown time
     */
    public function getTeleportCooldown(Player $player): int {
        $playerName = strtolower($player->getName());
        $currentTime = time();
        
        if (!isset($this->teleportCooldowns[$playerName])) {
            return 0;
        }
        
        $remaining = $this->teleportCooldowns[$playerName] - $currentTime;
        return max(0, $remaining);
    }
    
    /**
     * Check if position is safe for player
     */
    public function isSafePosition(Player $player, Position $position): bool {
        $world = $position->getWorld();
        if ($world === null) {
            return false;
        }
        
        $chunkX = $position->getFloorX() >> 4;
        $chunkZ = $position->getFloorZ() >> 4;
        
        $claim = $this->plugin->getClaimManager()->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
        
        // If no claim, position is safe (wilderness)
        if ($claim === null) {
            return true;
        }
        
        $playerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($player);
        $claimFaction = $claim->getFaction();
        
        // If player has no faction and area is claimed, not safe
        if ($playerFaction === null) {
            return false;
        }
        
        // If it's player's faction territory, safe
        if ($claimFaction !== null && $playerFaction->getName() === $claimFaction->getName()) {
            return true;
        }
        
        // If it's ally territory, safe
        if ($claimFaction !== null && $playerFaction->isAlly($claimFaction->getName())) {
            return true;
        }
        
        // If faction is under raid protection (freeze), safe for defenders
        if ($claimFaction !== null && $claimFaction->isFreeze() && $playerFaction->getName() === $claimFaction->getName()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Validate if player movement should be allowed
     */
    public function validateMovement(Player $player, Position $from, Position $to): bool {
        // Bypass check
        if ($this->hasBypass($player)) {
            return true;
        }
        
        // Creative/Spectator players bypass
        if ($player->getGamemode()->equals(\pocketmine\player\GameMode::CREATIVE()) || 
            $player->getGamemode()->equals(\pocketmine\player\GameMode::SPECTATOR())) {
            return true;
        }
        
        $world = $to->getWorld();
        if ($world === null) {
            return false;
        }
        
        $chunkX = $to->getFloorX() >> 4;
        $chunkZ = $to->getFloorZ() >> 4;
        
        $claim = $this->plugin->getClaimManager()->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
        
        // Wilderness is always accessible
        if ($claim === null) {
            $this->setLastSafePosition($player);
            return true;
        }
        
        $playerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($player);
        $claimFaction = $claim->getFaction();
        
        if ($claimFaction === null) {
            $this->setLastSafePosition($player);
            return true;
        }
        
        // Player has no faction - restricted in claimed areas
        if ($playerFaction === null) {
            return false;
        }
        
        // Own faction territory
        if ($playerFaction->getName() === $claimFaction->getName()) {
            $this->setLastSafePosition($player);
            return true;
        }
        
        // Allied territory
        if ($playerFaction->isAlly($claimFaction->getName())) {
            $this->setLastSafePosition($player);
            return true;
        }
        
        // Enemy territory during raid
        if ($claimFaction->isRaid()) {
            $this->setLastSafePosition($player);
            return true;
        }
        
        // Enemy territory with freeze protection
        if ($claimFaction->isFreeze()) {
            return false;
        }
        
        // Check faction power for raiding
        $attackerPower = $playerFaction->getPower();
        $defenderPower = $claimFaction->getPower();
        
        // Allow entry if attacker has more power
        if ($attackerPower > $defenderPower) {
            $this->setLastSafePosition($player);
            return true;
        }
        
        return false;
    }
    
    /**
     * Teleport player back to safe position
     */
    public function teleportToSafePosition(Player $player): bool {
        $safePosition = $this->getLastSafePosition($player);
        
        if ($safePosition === null) {
            // Fallback to spawn
            $safePosition = $player->getWorld()->getSpawnLocation();
        }
        
        // Ensure the position is still safe
        if (!$this->isSafePosition($player, $safePosition)) {
            $safePosition = $player->getWorld()->getSpawnLocation();
        }
        
        $player->teleport($safePosition);
        
        // Send message to player
        $message = $this->plugin->getMessageManager()->getMessage("antiglitch_teleport_safe");
        $player->sendMessage($message);
        
        return true;
    }
    
    /**
     * Clean up expired cooldowns
     */
    public function cleanupCooldowns(): void {
        $currentTime = time();
        
        foreach ($this->teleportCooldowns as $playerName => $cooldownTime) {
            if ($currentTime >= $cooldownTime) {
                unset($this->teleportCooldowns[$playerName]);
            }
        }
    }
}