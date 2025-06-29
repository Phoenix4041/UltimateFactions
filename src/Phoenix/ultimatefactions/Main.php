// Methods for updating ScoreHud when actions occur
    public function updatePlayerScoreHud(Player $player): void {
        if ($this->scoreHudManager !== null && $this->scoreHudManager->scoreHudExists()) {
            $this->scoreHudManager->updateAllPlayerTags($player);
        }
    }
    
    public function updateFactionMembersScoreHud(string $factionName): void {
        if ($this->scoreHudManager === null || !$this->scoreHudManager->scoreHudExists()) {
            return;
        }
        
        if ($this->factionManager === null) {
            return;
        }
        
        $faction = $this->factionManager->getFactionByName($factionName);
        if ($faction === null) return;
        
        foreach ($faction->getMembers() as $memberName) {
            $player = $this->getServer()->getPlayerExact($memberName);
            if ($player instanceof Player && $player->isOnline()) {
                $this->scoreHudManager->updateAllPlayerTags($player);
            }
        }
    }
    
    // Getters
    public static function getInstance(): Main {
        return self::$instance;
    }
    
    public function getDatabase(): DataConnector {
        return $this->database;
    }
    
    public function getEconomyProvider(): EconomyProvider {
        return $this->economyProvider;
    }
    
    public function getConfigManager(): ?Config {
        return $this->configManager;
    }
    
    public function getMessageManager(): ?Config {
        return $this->messageManager;
    }
    
    public function getFactionManager(): ?FactionManager {
        return $this->factionManager;
    }
    
    public function getPlayerManager(): ?PlayerManager {
        return $this->playerManager;
    }
    
    public function getClaimManager(): ?ClaimManager {
        return $this->claimManager;
    }
    
    public function getPowerManager(): ?PowerManager {
        return $this->powerManager;
    }
    
    public function getCooldownManager(): Main {
        // Return self since we're handling cooldowns directly in Main class
        return $this;
    }
    
    public function getScoreHudManager(): ?ScoreHudManager {
        return $this->scoreHudManager;
    }
    
    // Chunk border management
    public function enableChunkBorder(string $playerName): void {
        $this->borderPlayers[$playerName] = true;
    }
    
    public function disableChunkBorder(string $playerName): void {
        $this->borderPlayers[$playerName] = false;
    }
    
    public function isChunkBorderEnabled(string $playerName): bool {
        return $this->borderPlayers[$playerName] ?? false;
    }
    
    public function removePlayerFromBorderList(string $playerName): void {
        unset($this->borderPlayers[$playerName]);
    }
}    private function updateChunkBorders(): void {
        foreach ($this->borderPlayers as $playerName => $enabled) {
            if (!$enabled) continue;
            
            $player = $this->getServer()->getPlayerExact($playerName);
            if (!$player instanceof Player || !$player->isOnline()) {
                unset($this->borderPlayers[$playerName]);
                continue;
            }
            
            $this->showChunkBorder($player);
        }
    }
    
    private function updateAllPlayersScoreHud(): void {
        if ($this->scoreHudManager === null || !$this->scoreHudManager->scoreHudExists()) {
            return;
        }
        
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->scoreHudManager->updateAllPlayerTags($player);
        }
    }<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions;

use DaPigGuy\libPiggyEconomy\libPiggyEconomy;
use DaPigGuy\libPiggyEconomy\providers\EconomyProvider;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\math\Vector3;
use pocketmine\world\particle\DustParticle;
use pocketmine\color\Color;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use Phoenix\ultimatefactions\commands\FactionCommand;
use Phoenix\ultimatefactions\commands\FactionAdminCommand;
use Phoenix\ultimatefactions\listeners\PlayerListener;
use Phoenix\ultimatefactions\listeners\BlockListener;
use Phoenix\ultimatefactions\listeners\EntityListener;
use Phoenix\ultimatefactions\listeners\ChatListener;
use Phoenix\ultimatefactions\addons\scorehud\ScoreHudListener;
use Phoenix\ultimatefactions\addons\scorehud\ScoreHudManager;
use Phoenix\ultimatefactions\managers\FactionManager;
use Phoenix\ultimatefactions\managers\PlayerManager;
use Phoenix\ultimatefactions\managers\ClaimManager;
use Phoenix\ultimatefactions\managers\PowerManager;
use Exception;

class Main extends PluginBase {
    
    private static Main $instance;
    private DataConnector $database;
    private EconomyProvider $economyProvider;
    private ?Config $configManager = null;
    private ?Config $messageManager = null;
    private ?FactionManager $factionManager = null;
    private ?PlayerManager $playerManager = null;
    private ?ClaimManager $claimManager = null;
    private ?PowerManager $powerManager = null;
    private ?ScoreHudManager $scoreHudManager = null;
    
    // Basic storage for cooldowns until CooldownManager is created
    private array $cooldowns = [];
    
    private array $borderPlayers = [];
    private bool $crashed = false;
    
    public function onLoad(): void {
        self::$instance = $this;
    }
    
    public function onEnable(): void {
        $this->crashed = true;
        
        try {
            // Save default configurations
            $this->saveDefaultConfig();
            $this->saveResource("messages.yml");
            
            // Initialize configuration managers (using Config objects temporarily)
            $this->configManager = $this->getConfig();
            $this->messageManager = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
            
            // Initialize database
            $this->initDatabase();
            
            // Initialize economy
            $this->initEconomy();
            
            // Initialize managers
            $this->initManagers();
            
            // Register commands
            $this->registerCommands();
            
            // Register event listeners
            $this->registerListeners();
            
            // Start scheduled tasks
            $this->startTasks();
            
            $this->crashed = false;
            $this->getLogger()->info(TextFormat::GREEN . "UltimateFactions plugin enabled successfully!");
            
        } catch (Exception $e) {
            $this->getLogger()->error("Failed to enable UltimateFactions: " . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }
    
    public function onDisable(): void {
        if ($this->crashed) {
            return;
        }
        
        try {
            // Save all data
            if ($this->factionManager !== null) {
                $this->factionManager->saveAll();
            }
            if ($this->playerManager !== null) {
                $this->playerManager->saveAll();
            }
            // Save cooldowns
            $this->saveCooldowns();
            
            // Close database connection
            if (isset($this->database)) {
                $this->database->waitAll();
                $this->database->close();
            }
            
            $this->getLogger()->info(TextFormat::GREEN . "UltimateFactions plugin disabled successfully!");
            
        } catch (Exception $e) {
            $this->getLogger()->error("Error disabling UltimateFactions: " . $e->getMessage());
        }
    }
    
    private function initDatabase(): void {
        $dbConfig = $this->getConfig()->get("database", []);
        
        $this->database = libasynql::create($this, $dbConfig, [
            "sqlite" => "database/sqlite.sql",
            "mysql" => "database/mysql.sql"
        ]);
        
        // Initialize database tables
        $this->database->executeGeneric("table.factions");
        $this->database->executeGeneric("table.players");
        $this->database->executeGeneric("table.claims");
        $this->database->executeGeneric("table.faction_invites");
        $this->database->executeGeneric("table.ally_requests");
        $this->database->executeGeneric("table.faction_logs");
        
        $this->database->waitAll();
    }
    
    private function initEconomy(): void {
        libPiggyEconomy::init();
        $economyConfig = $this->getConfig()->get("economy", []);
        $provider = $economyConfig["provider"] ?? "economyapi";
        
        $this->economyProvider = libPiggyEconomy::getProvider($provider);
        
        if ($this->economyProvider === null) {
            throw new Exception("Economy provider '$provider' not found!");
        }
    }
    
    private function initManagers(): void {
        // Initialize basic cooldown management
        $this->loadCooldowns();
        
        // Initialize PowerManager
        if (class_exists("Phoenix\\ultimatefactions\\managers\\PowerManager")) {
            $this->powerManager = new PowerManager($this);
        }
        
        // Initialize ClaimManager
        if (class_exists("Phoenix\\ultimatefactions\\managers\\ClaimManager")) {
            $this->claimManager = new ClaimManager($this);
        }
        
        // Initialize PlayerManager
        if (class_exists("Phoenix\\ultimatefactions\\managers\\PlayerManager")) {
            $this->playerManager = new PlayerManager($this);
        }
        
        // Initialize FactionManager
        if (class_exists("Phoenix\\ultimatefactions\\managers\\FactionManager")) {
            $this->factionManager = new FactionManager($this);
        }
        
        // Initialize ScoreHud if available
        $this->scoreHudManager = ScoreHudManager::getInstance();
        
        // Initialize managers with database
        if ($this->factionManager !== null) {
            $this->factionManager->init();
        }
        if ($this->playerManager !== null) {
            $this->playerManager->init();
        }
        if ($this->claimManager !== null) {
            $this->claimManager->init();
        }
    }
    
    private function registerCommands(): void {
        $commandMap = $this->getServer()->getCommandMap();
        
        if (class_exists("Phoenix\\ultimatefactions\\commands\\FactionCommand")) {
            $commandMap->register("ultimatefactions", new FactionCommand($this));
        }
        if (class_exists("Phoenix\\ultimatefactions\\commands\\FactionAdminCommand")) {
            $commandMap->register("ultimatefactionsadmin", new FactionAdminCommand($this));
        }
    }
    
    private function registerListeners(): void {
        $pluginManager = $this->getServer()->getPluginManager();
        
        if (class_exists("Phoenix\\ultimatefactions\\listeners\\PlayerListener")) {
            $pluginManager->registerEvents(new PlayerListener($this), $this);
        }
        if (class_exists("Phoenix\\ultimatefactions\\listeners\\BlockListener")) {
            $pluginManager->registerEvents(new BlockListener($this), $this);
        }
        if (class_exists("Phoenix\\ultimatefactions\\listeners\\EntityListener")) {
            $pluginManager->registerEvents(new EntityListener($this), $this);
        }
        if (class_exists("Phoenix\\ultimatefactions\\listeners\\ChatListener")) {
            $pluginManager->registerEvents(new ChatListener($this), $this);
        }
        
        // Register ScoreHud listener if ScoreHud is available
        if ($this->scoreHudManager !== null && $this->scoreHudManager->scoreHudExists()) {
            if (class_exists("Phoenix\\ultimatefactions\\addons\\scorehud\\ScoreHudListener")) {
                $pluginManager->registerEvents(new ScoreHudListener(), $this);
                $this->getLogger()->info(TextFormat::GREEN . "ScoreHud integration enabled!");
            }
        } else {
            $this->getLogger()->info(TextFormat::YELLOW . "ScoreHud not found or incompatible version. ScoreHud integration disabled.");
        }
    }
    
    private function startTasks(): void {
        // Task to update faction freeze status
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->updateFactionFreezeStatus();
            }),
            20 * 60 // Every minute
        );
        
        // Task to update chunk borders for players
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->updateChunkBorders();
            }),
            20 // Every second
        );
        
        // Task to clean expired invites
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->database->executeGeneric("data.cleanExpiredInvites");
            }),
            20 * 60 * 5 // Every 5 minutes
        );
        
        // Task to save data periodically
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->saveAllData();
            }),
            20 * 60 * 10 // Every 10 minutes
        );
        
        // Task to clean expired cooldowns
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->cleanExpiredCooldowns();
            }),
            20 * 60 // Every minute
        );
        
        // Task to update ScoreHud tags for all online players
        if ($this->scoreHudManager !== null && $this->scoreHudManager->scoreHudExists()) {
            $this->getScheduler()->scheduleRepeatingTask(
                new ClosureTask(function(): void {
                    $this->updateAllPlayersScoreHud();
                }),
                20 * 30 // Every 30 seconds
            );
        }
    }
    
    private function updateFactionFreezeStatus(): void {
        if ($this->factionManager === null) return;
        
        foreach ($this->factionManager->getFactions() as $faction) {
            if ($faction->isFreeze()) {
                $freezeTime = $faction->getFreezeTime();
                $currentTime = time();
                
                if ($freezeTime <= $currentTime) {
                    $faction->setFreeze(false);
                    $faction->setFreezeTime(0);
                    
                    // Notify faction members
                    $message = $this->getMessage("faction_raid_protection");
                    $faction->broadcastMessage($message);
                    
                    $this->factionManager->updateFaction($faction);
                    
                    // Update ScoreHud for faction members
                    if ($this->scoreHudManager !== null && $this->scoreHudManager->scoreHudExists()) {
                        foreach ($faction->getMembers() as $memberName) {
                            $player = $this->getServer()->getPlayerExact($memberName);
                            if ($player instanceof Player && $player->isOnline()) {
                                $this->scoreHudManager->updatePlayerFactionFreezeTimeTag($player, null);
                            }
                        }
                    }
                }
            }
        }
    }
    
    private function showChunkBorder(Player $player): void {
        $world = $player->getWorld();
        $pos = $player->getPosition();
        
        $chunkX = $pos->getFloorX() >> Chunk::COORD_BIT_SIZE;
        $chunkZ = $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE;
        
        $minX = $chunkX * 16;
        $maxX = $minX + 16;
        $minZ = $chunkZ * 16;
        $maxZ = $minZ + 16;
        
        $y = $pos->getY() + 1;
        
        // Get claim info
        $claim = null;
        if ($this->claimManager !== null) {
            $claim = $this->claimManager->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
        }
        $color = $this->getChunkBorderColor($player, $claim);
        
        // Draw border particles
        for ($x = $minX; $x <= $maxX; $x += 0.5) {
            $world->addParticle(new Vector3($x, $y, $minZ), new DustParticle($color), [$player]);
            $world->addParticle(new Vector3($x, $y, $maxZ), new DustParticle($color), [$player]);
        }
        
        for ($z = $minZ; $z <= $maxZ; $z += 0.5) {
            $world->addParticle(new Vector3($minX, $y, $z), new DustParticle($color), [$player]);
            $world->addParticle(new Vector3($maxX, $y, $z), new DustParticle($color), [$player]);
        }
    }
    
    private function getChunkBorderColor(Player $player, ?object $claim): Color {
        if ($claim === null) {
            return new Color(255, 255, 255); // White for wilderness
        }
        
        if ($this->playerManager === null) {
            return new Color(255, 0, 0); // Red for enemy
        }
        
        $playerFaction = $this->playerManager->getPlayerFaction($player);
        if ($playerFaction === null) {
            return new Color(255, 0, 0); // Red for enemy
        }
        
        $claimFaction = $claim->getFaction();
        if ($claimFaction === null) {
            return new Color(255, 255, 255); // White for wilderness
        }
        
        if ($playerFaction->getName() === $claimFaction->getName()) {
            return new Color(0, 255, 0); // Green for own faction
        }
        
        if ($playerFaction->isAlly($claimFaction->getName())) {
            return new Color(0, 0, 255); // Blue for ally
        }
        
        return new Color(255, 0, 0); // Red for enemy
    }
    
    private function saveAllData(): void {
        try {
            if ($this->factionManager !== null) {
                $this->factionManager->saveAll();
            }
            if ($this->playerManager !== null) {
                $this->playerManager->saveAll();
            }
            $this->saveCooldowns();
        } catch (Exception $e) {
            $this->getLogger()->warning("Failed to save data: " . $e->getMessage());
        }
    }
    
    // Cooldown management methods
    private function loadCooldowns(): void {
        $file = $this->getDataFolder() . "cooldowns.json";
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            $this->cooldowns = $data ?? [];
        }
    }
    
    private function saveCooldowns(): void {
        $file = $this->getDataFolder() . "cooldowns.json";
        file_put_contents($file, json_encode($this->cooldowns, JSON_PRETTY_PRINT));
    }
    
    private function cleanExpiredCooldowns(): void {
        $currentTime = time();
        foreach ($this->cooldowns as $key => $cooldown) {
            if ($cooldown['expires'] <= $currentTime) {
                unset($this->cooldowns[$key]);
            }
        }
    }
    
    public function addCooldown(string $player, string $type, int $duration): void {
        $this->cooldowns[$player . ":" . $type] = [
            'expires' => time() + $duration,
            'type' => $type
        ];
    }
    
    public function hasCooldown(string $player, string $type): bool {
        $key = $player . ":" . $type;
        if (!isset($this->cooldowns[$key])) {
            return false;
        }
        
        if ($this->cooldowns[$key]['expires'] <= time()) {
            unset($this->cooldowns[$key]);
            return false;
        }
        
        return true;
    }
    
    public function getCooldownTime(string $player, string $type): int {
        $key = $player . ":" . $type;
        if (!isset($this->cooldowns[$key])) {
            return 0;
        }
        
        $remaining = $this->cooldowns[$key]['expires'] - time();
        return max(0, $remaining);
    }
    
    // Message helper method
    public function getMessage(string $key, array $placeholders = []): string {
        if ($this->messageManager === null) {
            return $key;
        }
        
        $message = $this->messageManager->get($key, $key);
        
        foreach ($placeholders as $placeholder => $value) {
            $message = str_replace("{" . $placeholder . "}", (string)$value, $message);
        }
        
        return TextFormat::colorize($message);
    }
    
    public function reloadConfigs(): void {
        $this->reloadConfig();
        if ($this->messageManager !== null) {
            $this->messageManager->reload();
        }
    }