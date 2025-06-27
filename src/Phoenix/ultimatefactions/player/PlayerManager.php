<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\managers;

use pocketmine\player\Player;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\objects\FactionPlayer;
use Phoenix\ultimatefactions\objects\Faction;
use Exception;

class PlayerManager {
    
    private Main $plugin;
    private array $players = [];
    private array $factionChat = [];
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function init(): void {
        $this->loadPlayers();
    }
    
    private function loadPlayers(): void {
        $this->plugin->getDatabase()->executeSelect("players.getAll", [], function(array $rows): void {
            $this->players = [];
            
            foreach ($rows as $row) {
                $this->players[$row["player_name"]] = new FactionPlayer(
                    $row["player_name"],
                    $row["faction_name"],
                    $row["role"],
                    (int) $row["power"],
                    (int) $row["max_power"],
                    (int) $row["kills"],
                    (int) $row["deaths"],
                    (int) $row["last_seen"]
                );
            }
            
            $this->plugin->getLogger()->info("Loaded " . count($this->players) . " players");
        });
    }
    
    /**
     * Get all faction players
     * @return FactionPlayer[]
     */
    public function getPlayers(): array {
        return $this->players;
    }
    
    /**
     * Get a player session by name
     * @param string $playerName
     * @return FactionPlayer|null
     */
    public function getPlayerByName(string $playerName): ?FactionPlayer {
        return $this->players[$playerName] ?? null;
    }
    
    /**
     * Check if a player exists in the system
     * @param string $playerName
     * @return bool
     */
    public function playerExists(string $playerName): bool {
        return isset($this->players[$playerName]);
    }
    
    /**
     * Get a player's faction
     * @param Player $player
     * @return Faction|null
     */
    public function getPlayerFaction(Player $player): ?Faction {
        $factionPlayer = $this->getPlayerByName($player->getName());
        
        if ($factionPlayer === null || !$factionPlayer->hasFaction()) {
            return null;
        }
        
        return $this->plugin->getFactionManager()->getFactionByName($factionPlayer->getFactionName());
    }
    
    /**
     * Create a new player session
     * @param string $playerName
     * @param string|null $factionName
     * @param string $role
     * @param int $power
     * @param int $maxPower
     */
    public function createPlayer(string $playerName, ?string $factionName = null, string $role = "member", int $power = 0, int $maxPower = 100): void {
        $defaultPower = $this->plugin->getConfigManager()->getDefaultPower();
        $defaultMaxPower = $this->plugin->getConfigManager()->getMaxPower();
        
        $factionPlayer = new FactionPlayer(
            $playerName,
            $factionName,
            $role,
            $power > 0 ? $power : $defaultPower,
            $maxPower > 0 ? $maxPower : $defaultMaxPower,
            0, // kills
            0, // deaths
            time() // last_seen
        );
        
        $this->players[$playerName] = $factionPlayer;
        
        // Save to database
        $this->plugin->getDatabase()->executeInsert("players.create", [
            "player_name" => $playerName,
            "faction_name" => $factionName,
            "role" => $role,
            "power" => $factionPlayer->getPower(),
            "max_power" => $factionPlayer->getMaxPower(),
            "kills" => 0,
            "deaths" => 0,
            "last_seen" => time()
        ]);
    }
    
    /**
     * Update a player's data
     * @param FactionPlayer $factionPlayer
     */
    public function updatePlayer(FactionPlayer $factionPlayer): void {
        $this->players[$factionPlayer->getName()] = $factionPlayer;
        
        // Update in database
        $this->plugin->getDatabase()->executeChange("players.update", [
            "player_name" => $factionPlayer->getName(),
            "faction_name" => $factionPlayer->getFactionName(),
            "role" => $factionPlayer->getRole(),
            "power" => $factionPlayer->getPower(),
            "max_power" => $factionPlayer->getMaxPower(),
            "kills" => $factionPlayer->getKills(),
            "deaths" => $factionPlayer->getDeaths(),
            "last_seen" => $factionPlayer->getLastSeen()
        ]);
    }
    
    /**
     * Remove a player from the system
     * @param string $playerName
     */
    public function removePlayer(string $playerName): void {
        unset($this->players[$playerName]);
        unset($this->factionChat[$playerName]);
        
        // Remove from database
        $this->plugin->getDatabase()->executeChange("players.delete", [
            "player_name" => $playerName
        ]);
    }
    
    /**
     * Check if two players are allied (same faction or allied factions)
     * @param Player $playerA
     * @param Player $playerB
     * @return bool
     */
    public function areAllied(Player $playerA, Player $playerB): bool {
        $factionA = $this->getPlayerFaction($playerA);
        $factionB = $this->getPlayerFaction($playerB);
        
        // If either player has no faction, they're not allied
        if ($factionA === null || $factionB === null) {
            return false;
        }
        
        // Same faction
        if ($factionA->getName() === $factionB->getName()) {
            return true;
        }
        
        // Allied factions
        return $factionA->isAlly($factionB->getName());
    }
    
    /**
     * Check if two players are enemies
     * @param Player $playerA
     * @param Player $playerB
     * @return bool
     */
    public function areEnemies(Player $playerA, Player $playerB): bool {
        return !$this->areAllied($playerA, $playerB);
    }
    
    /**
     * Update player's last seen time
     * @param string $playerName
     */
    public function updateLastSeen(string $playerName): void {
        $factionPlayer = $this->getPlayerByName($playerName);
        if ($factionPlayer !== null) {
            $factionPlayer->setLastSeen(time());
            $this->updatePlayer($factionPlayer);
        }
    }
    
    /**
     * Add power to a player
     * @param string $playerName
     * @param int $amount
     */
    public function addPower(string $playerName, int $amount): void {
        $factionPlayer = $this->getPlayerByName($playerName);
        if ($factionPlayer !== null) {
            $newPower = min($factionPlayer->getPower() + $amount, $factionPlayer->getMaxPower());
            $factionPlayer->setPower($newPower);
            $this->updatePlayer($factionPlayer);
        }
    }
    
    /**
     * Remove power from a player
     * @param string $playerName
     * @param int $amount
     */
    public function removePower(string $playerName, int $amount): void {
        $factionPlayer = $this->getPlayerByName($playerName);
        if ($factionPlayer !== null) {
            $newPower = max($factionPlayer->getPower() - $amount, 0);
            $factionPlayer->setPower($newPower);
            $this->updatePlayer($factionPlayer);
        }
    }
    
    /**
     * Set player's power
     * @param string $playerName
     * @param int $power
     */
    public function setPower(string $playerName, int $power): void {
        $factionPlayer = $this->getPlayerByName($playerName);
        if ($factionPlayer !== null) {
            $clampedPower = max(0, min($power, $factionPlayer->getMaxPower()));
            $factionPlayer->setPower($clampedPower);
            $this->updatePlayer($factionPlayer);
        }
    }
    
    /**
     * Handle player kill (add power and increment kills)
     * @param string $killerName
     * @param string $victimName
     */
    public function handlePlayerKill(string $killerName, string $victimName): void {
        $killer = $this->getPlayerByName($killerName);
        $victim = $this->getPlayerByName($victimName);
        
        if ($killer !== null) {
            $powerGain = $this->plugin->getConfigManager()->getPowerPerKill();
            $this->addPower($killerName, $powerGain);
            $killer->addKill();
            $this->updatePlayer($killer);
        }
        
        if ($victim !== null) {
            $powerLoss = $this->plugin->getConfigManager()->getPowerPerDeath();
            $this->removePower($victimName, abs($powerLoss));
            $victim->addDeath();
            $this->updatePlayer($victim);
        }
    }
    
    /**
     * Toggle faction chat for a player
     * @param string $playerName
     * @return bool New state
     */
    public function toggleFactionChat(string $playerName): bool {
        $this->factionChat[$playerName] = !($this->factionChat[$playerName] ?? false);
        return $this->factionChat[$playerName];
    }
    
    /**
     * Check if player has faction chat enabled
     * @param string $playerName
     * @return bool
     */
    public function hasFactionChatEnabled(string $playerName): bool {
        return $this->factionChat[$playerName] ?? false;
    }
    
    /**
     * Set faction chat state for a player
     * @param string $playerName
     * @param bool $enabled
     */
    public function setFactionChat(string $playerName, bool $enabled): void {
        $this->factionChat[$playerName] = $enabled;
    }
    
    /**
     * Get players by faction name
     * @param string $factionName
     * @return FactionPlayer[]
     */
    public function getPlayersByFaction(string $factionName): array {
        $players = [];
        
        foreach ($this->players as $player) {
            if ($player->getFactionName() === $factionName) {
                $players[] = $player;
            }
        }
        
        return $players;
    }
    
    /**
     * Get online players by faction name
     * @param string $factionName
     * @return Player[]
     */
    public function getOnlinePlayersByFaction(string $factionName): array {
        $onlinePlayers = [];
        
        foreach ($this->getPlayersByFaction($factionName) as $factionPlayer) {
            $player = $this->plugin->getServer()->getPlayerExact($factionPlayer->getName());
            if ($player instanceof Player && $player->isOnline()) {
                $onlinePlayers[] = $player;
            }
        }
        
        return $onlinePlayers;
    }
    
    /**
     * Save all player data
     */
    public function saveAll(): void {
        try {
            foreach ($this->players as $factionPlayer) {
                $this->plugin->getDatabase()->executeChange("players.update", [
                    "player_name" => $factionPlayer->getName(),
                    "faction_name" => $factionPlayer->getFactionName(),
                    "role" => $factionPlayer->getRole(),
                    "power" => $factionPlayer->getPower(),
                    "max_power" => $factionPlayer->getMaxPower(),
                    "kills" => $factionPlayer->getKills(),
                    "deaths" => $factionPlayer->getDeaths(),
                    "last_seen" => $factionPlayer->getLastSeen()
                ]);
            }
            
            $this->plugin->getDatabase()->waitAll();
            $this->plugin->getLogger()->debug("Saved " . count($this->players) . " players");
            
        } catch (Exception $e) {
            $this->plugin->getLogger()->error("Failed to save players: " . $e->getMessage());
        }
    }
    
    /**
     * Get player count
     * @return int
     */
    public function getPlayerCount(): int {
        return count($this->players);
    }
    
    /**
     * Get total faction power by faction name
     * @param string $factionName
     * @return int
     */
    public function getTotalFactionPower(string $factionName): int {
        $totalPower = 0;
        
        foreach ($this->getPlayersByFaction($factionName) as $player) {
            $totalPower += $player->getPower();
        }
        
        return $totalPower;
    }
    
    /**
     * Get faction member count
     * @param string $factionName
     * @return int
     */
    public function getFactionMemberCount(string $factionName): int {
        return count($this->getPlayersByFaction($factionName));
    }
    
    /**
     * Check if player can perform faction actions
     * @param Player $player
     * @param string $action
     * @return bool
     */
    public function canPerformAction(Player $player, string $action): bool {
        $factionPlayer = $this->getPlayerByName($player->getName());
        
        if ($factionPlayer === null || !$factionPlayer->hasFaction()) {
            return false;
        }
        
        $role = $factionPlayer->getRole();
        
        switch ($action) {
            case "invite":
            case "kick":
            case "promote":
            case "demote":
            case "claim":
            case "unclaim":
                return in_array($role, ["leader", "officer"]);
            case "disband":
            case "ally":
            case "unally":
                return $role === "leader";
            default:
                return true;
        }
    }
}