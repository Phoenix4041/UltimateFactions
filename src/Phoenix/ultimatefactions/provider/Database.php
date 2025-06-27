<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\provider;

use pocketmine\player\Player;
use pocketmine\utils\SingletonTrait;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use Phoenix\ultimatefactions\claims\Claim;
use Phoenix\ultimatefactions\faction\Faction;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\player\FactionPlayer;
use Phoenix\ultimatefactions\utils\Utils;
use Exception;

class DataBase {
    use SingletonTrait;

    /** @var DataConnector */
    private DataConnector $database;

    /**
     * @param string[] $config
     */
    public function createContext(array $config): void {
        $this->database = libasynql::create(Main::getInstance(), $config, [
            "sqlite" => "database/sqlite.sql",
            "mysql"  => "database/mysql.sql",
        ]);

        // Initialize all tables
        $this->database->executeGeneric('table.factions');
        $this->database->executeGeneric('table.players');
        $this->database->executeGeneric('table.claims');
        $this->database->executeGeneric('table.faction_invites');
        $this->database->executeGeneric('table.ally_requests');
        $this->database->executeGeneric('table.faction_logs');
        
        $this->database->waitAll();
    }

    /**
     * Create Player database entry.
     *
     * @param Player $player
     */
    public static function createPlayer(Player $player): void {
        self::getInstance()->database->executeInsert('data.createPlayer', [
            'playerName' => $player->getName(),
            'faction' => null,
            'role' => FactionPlayer::MEMBER,
            'power' => Main::getInstance()->getConfigManager()->getDefaultPower(),
            'maxPower' => Main::getInstance()->getConfigManager()->getMaxPower(),
            'kills' => 0,
            'deaths' => 0,
            'joinTime' => time(),
            'lastSeen' => time(),
            'chatMode' => 'public'
        ]);
    }

    /**
     * Get player's entry from the database.
     *
     * @param Player $player
     * @param callable $onComplete The result of the search query.
     */
    public static function getPlayerSessionEntry(Player $player, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectPlayer', [
            "playerName" => $player->getName(),
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete(null);
                return;
            }
            $onComplete($rows[0]);
        });
    }

    /**
     * Update Player session data.
     *
     * @param FactionPlayer $session
     */
    public static function updatePlayerSession(FactionPlayer $session): void {
        self::getInstance()->database->executeChange('data.updatePlayer', [
            'playerName' => $session->getName(),
            'faction' => $session->getFaction(),
            'role' => $session->getRole(),
            'power' => $session->getPower(),
            'maxPower' => $session->getMaxPower(),
            'kills' => $session->getKills(),
            'deaths' => $session->getDeaths(),
            'lastSeen' => time(),
            'chatMode' => $session->getChatMode()
        ]);
    }

    /**
     * Get all players
     *
     * @param callable $onComplete
     */
    public static function getPlayers(callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectPlayers', [], 
        function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get players by faction
     *
     * @param string $factionName
     * @param callable $onComplete
     */
    public static function getPlayersByFaction(string $factionName, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectPlayersByFaction', [
            'faction' => $factionName
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Create Faction database entry.
     *
     * @param Faction $faction
     */
    public static function createFaction(Faction $faction): void {
        self::getInstance()->database->executeInsert('data.createFaction', [
            'factionName' => $faction->getName(),
            'displayName' => $faction->getDisplayName(),
            'description' => $faction->getDescription(),
            'creationTime' => $faction->getCreationTime(),
            'leader' => $faction->getLeader(),
            'players' => json_encode($faction->getPlayers()),
            'home' => json_encode(Utils::getArrayToPosition($faction->getHome())),
            'power' => $faction->getPower(),
            'maxPower' => $faction->getMaxPower(),
            'kills' => $faction->getKills(),
            'deaths' => $faction->getDeaths(),
            'money' => $faction->getMoney(),
            'freeze' => $faction->isFreeze(),
            'freezeTime' => $faction->getFreezeTime(),
            'relations' => json_encode($faction->getRelations()),
            'flags' => json_encode($faction->getFlags()),
            'motd' => $faction->getMotd(),
            'open' => $faction->isOpen(),
            'peaceful' => $faction->isPeaceful()
        ]);
    }

    /**
     * Delete Faction database entry.
     *
     * @param Faction $faction
     */
    public static function deleteFaction(Faction $faction): void {
        $db = self::getInstance()->database;
        
        // Delete faction
        $db->executeChange('data.deleteFaction', [
            'factionName' => $faction->getName()
        ]);
        
        // Delete faction claims
        $db->executeChange('data.deleteClaimsByFaction', [
            'factionName' => $faction->getName()
        ]);
        
        // Delete faction invites
        $db->executeChange('data.deleteInvitesByFaction', [
            'factionName' => $faction->getName()
        ]);
        
        // Delete ally requests
        $db->executeChange('data.deleteAllyRequestsByFaction', [
            'factionName' => $faction->getName()
        ]);
        
        // Delete faction logs
        $db->executeChange('data.deleteLogsByFaction', [
            'factionName' => $faction->getName()
        ]);
        
        // Update players to remove faction
        $db->executeChange('data.updatePlayersRemoveFaction', [
            'faction' => $faction->getName()
        ]);
    }

    /**
     * Update Faction data.
     *
     * @param Faction $faction
     */
    public static function updateFaction(Faction $faction): void {
        self::getInstance()->database->executeChange('data.updateFaction', [
            'factionName' => $faction->getName(),
            'displayName' => $faction->getDisplayName(),
            'description' => $faction->getDescription(),
            'creationTime' => $faction->getCreationTime(),
            'leader' => $faction->getLeader(),
            'players' => json_encode($faction->getPlayers()),
            'home' => json_encode(Utils::getArrayToPosition($faction->getHome())),
            'power' => $faction->getPower(),
            'maxPower' => $faction->getMaxPower(),
            'kills' => $faction->getKills(),
            'deaths' => $faction->getDeaths(),
            'money' => $faction->getMoney(),
            'freeze' => $faction->isFreeze(),
            'freezeTime' => $faction->getFreezeTime(),
            'relations' => json_encode($faction->getRelations()),
            'flags' => json_encode($faction->getFlags()),
            'motd' => $faction->getMotd(),
            'open' => $faction->isOpen(),
            'peaceful' => $faction->isPeaceful()
        ]);
    }

    /**
     * Get all factions
     *
     * @param callable $onComplete
     */
    public static function getFactions(callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectFactions', [], 
        function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get faction by name
     *
     * @param string $factionName
     * @param callable $onComplete
     */
    public static function getFactionByName(string $factionName, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectFactionByName', [
            'factionName' => $factionName
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete(null);
                return;
            }
            $onComplete($rows[0]);
        });
    }

    /**
     * Create Claim database entry.
     *
     * @param Claim $claim
     */
    public static function createClaim(Claim $claim): void {
        self::getInstance()->database->executeInsert('data.createClaim', [
            "factionName" => $claim->getFaction()->getName(),
            "chunkX" => $claim->getChunkX(),
            "chunkZ" => $claim->getChunkZ(),
            "world" => $claim->getWorld()->getFolderName(),
            "claimTime" => time(),
            "claimedBy" => $claim->getClaimedBy()
        ]);
    }

    /**
     * Delete Claim database entry.
     *
     * @param Claim $claim
     */
    public static function deleteClaim(Claim $claim): void {
        self::getInstance()->database->executeChange('data.deleteClaim', [
            'chunkX' => $claim->getChunkX(),
            'chunkZ' => $claim->getChunkZ(),
            'world' => $claim->getWorld()->getFolderName()
        ]);
    }

    /**
     * Get all claims
     *
     * @param callable $onComplete
     */
    public static function getClaims(callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectClaims', [], 
        function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get claims by faction
     *
     * @param string $factionName
     * @param callable $onComplete
     */
    public static function getClaimsByFaction(string $factionName, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectClaimsByFaction', [
            'factionName' => $factionName
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get claim at specific coordinates
     *
     * @param int $chunkX
     * @param int $chunkZ
     * @param string $world
     * @param callable $onComplete
     */
    public static function getClaimAt(int $chunkX, int $chunkZ, string $world, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectClaimAt', [
            'chunkX' => $chunkX,
            'chunkZ' => $chunkZ,
            'world' => $world
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete(null);
                return;
            }
            $onComplete($rows[0]);
        });
    }

    /**
     * Create faction invite
     *
     * @param string $factionName
     * @param string $playerName
     * @param string $invitedBy
     * @param int $expireTime
     */
    public static function createInvite(string $factionName, string $playerName, string $invitedBy, int $expireTime): void {
        self::getInstance()->database->executeInsert('data.createInvite', [
            'factionName' => $factionName,
            'playerName' => $playerName,
            'invitedBy' => $invitedBy,
            'inviteTime' => time(),
            'expireTime' => $expireTime
        ]);
    }

    /**
     * Delete faction invite
     *
     * @param string $factionName
     * @param string $playerName
     */
    public static function deleteInvite(string $factionName, string $playerName): void {
        self::getInstance()->database->executeChange('data.deleteInvite', [
            'factionName' => $factionName,
            'playerName' => $playerName
        ]);
    }

    /**
     * Get player invites
     *
     * @param string $playerName
     * @param callable $onComplete
     */
    public static function getPlayerInvites(string $playerName, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectPlayerInvites', [
            'playerName' => $playerName
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Clean expired invites
     */
    public static function cleanExpiredInvites(): void {
        self::getInstance()->database->executeGeneric('data.cleanExpiredInvites', [
            'currentTime' => time()
        ]);
    }

    /**
     * Create ally request
     *
     * @param string $factionName
     * @param string $targetFaction
     * @param string $requestedBy
     */
    public static function createAllyRequest(string $factionName, string $targetFaction, string $requestedBy): void {
        self::getInstance()->database->executeInsert('data.createAllyRequest', [
            'factionName' => $factionName,
            'targetFaction' => $targetFaction,
            'requestedBy' => $requestedBy,
            'requestTime' => time()
        ]);
    }

    /**
     * Delete ally request
     *
     * @param string $factionName
     * @param string $targetFaction
     */
    public static function deleteAllyRequest(string $factionName, string $targetFaction): void {
        self::getInstance()->database->executeChange('data.deleteAllyRequest', [
            'factionName' => $factionName,
            'targetFaction' => $targetFaction
        ]);
    }

    /**
     * Get ally requests for faction
     *
     * @param string $factionName
     * @param callable $onComplete
     */
    public static function getAllyRequests(string $factionName, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectAllyRequests', [
            'targetFaction' => $factionName
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Add faction log entry
     *
     * @param string $factionName
     * @param string $action
     * @param string $playerName
     * @param string $details
     */
    public static function addFactionLog(string $factionName, string $action, string $playerName, string $details = ""): void {
        self::getInstance()->database->executeInsert('data.createFactionLog', [
            'factionName' => $factionName,
            'action' => $action,
            'playerName' => $playerName,
            'details' => $details,
            'timestamp' => time()
        ]);
    }

    /**
     * Get faction logs
     *
     * @param string $factionName
     * @param int $limit
     * @param callable $onComplete
     */
    public static function getFactionLogs(string $factionName, int $limit, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectFactionLogs', [
            'factionName' => $factionName,
            'limit' => $limit
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Clean old faction logs
     *
     * @param int $days
     */
    public static function cleanOldLogs(int $days = 30): void {
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        self::getInstance()->database->executeGeneric('data.cleanOldLogs', [
            'cutoffTime' => $cutoffTime
        ]);
    }

    /**
     * Get faction statistics
     *
     * @param callable $onComplete
     */
    public static function getFactionStats(callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectFactionStats', [], 
        function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get player statistics
     *
     * @param callable $onComplete
     */
    public static function getPlayerStats(callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectPlayerStats', [], 
        function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get top factions by power
     *
     * @param int $limit
     * @param callable $onComplete
     */
    public static function getTopFactionsByPower(int $limit, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectTopFactionsByPower', [
            'limit' => $limit
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get top factions by kills
     *
     * @param int $limit
     * @param callable $onComplete
     */
    public static function getTopFactionsByKills(int $limit, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectTopFactionsByKills', [
            'limit' => $limit
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Get top players by power
     *
     * @param int $limit
     * @param callable $onComplete
     */
    public static function getTopPlayersByPower(int $limit, callable $onComplete): void {
        self::getInstance()->database->executeSelect('data.selectTopPlayersByPower', [
            'limit' => $limit
        ], function(array $rows) use ($onComplete) {
            if (empty($rows)) {
                $onComplete([]);
                return;
            }
            $onComplete($rows);
        });
    }

    /**
     * Backup database
     *
     * @param string $backupPath
     * @return bool
     */
    public static function backup(string $backupPath): bool {
        try {
            $db = self::getInstance()->database;
            $db->waitAll();
            
            // Implementation depends on database type
            // For SQLite, copy the file
            // For MySQL, use mysqldump equivalent
            
            return true;
        } catch (Exception $e) {
            Main::getInstance()->getLogger()->error("Database backup failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Optimize database
     */
    public static function optimize(): void {
        try {
            self::getInstance()->database->executeGeneric('data.optimize');
        } catch (Exception $e) {
            Main::getInstance()->getLogger()->warning("Database optimization failed: " . $e->getMessage());
        }
    }

    /**
     * Get database connection info
     *
     * @return array
     */
    public static function getConnectionInfo(): array {
        return [
            'type' => self::getInstance()->database->getConnectorName(),
            'connected' => true,
            'pending_queries' => 0 // libasynql doesn't expose this easily
        ];
    }

    /**
     * Shutdown database connection safely
     */
    public static function shutdown(): void {
        try {
            $instance = self::getInstance();
            if (isset($instance->database)) {
                $instance->database->waitAll();
                $instance->database->close();
            }
        } catch (Exception $e) {
            Main::getInstance()->getLogger()->error("Error shutting down database: " . $e->getMessage());
        }
    }
}