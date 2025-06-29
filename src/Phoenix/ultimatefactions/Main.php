<?php

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
use Phoenix\ultimatefactions\managers\CooldownManager;
use Phoenix\ultimatefactions\utils\ConfigManager;
use Phoenix\ultimatefactions\utils\MessageManager;
use Exception;

class Main extends PluginBase {
    
    private static Main $instance;
    private DataConnector $database;
    private EconomyProvider $economyProvider;
    private ConfigManager $configManager;
    private MessageManager $messageManager;
    private FactionManager $factionManager;
    private PlayerManager $playerManager;
    private ClaimManager $claimManager;
    private PowerManager $powerManager;
    private CooldownManager $cooldownManager;
    private ?ScoreHudManager $scoreHudManager = null;
    
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
            
            // Initialize configuration and message managers
            $this->configManager = new ConfigManager($this);
            $this->messageManager = new MessageManager($this);
            
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
            $this->factionManager?->saveAll();
            $this->playerManager?->saveAll();
            $this->cooldownManager?->save();
            
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
        $this->cooldownManager = new CooldownManager($this);
        $this->powerManager = new PowerManager($this);
        $this->claimManager = new ClaimManager($this);
        $this->playerManager = new PlayerManager($this);
        $this->factionManager = new FactionManager($this);
        
        // Initialize ScoreHud if available
        $this->scoreHudManager = ScoreHudManager::getInstance();
        
        // Initialize managers with database
        $this->factionManager->init();
        $this->playerManager->init();
        $this->claimManager->init();
    }
    
    private function registerCommands(): void {
        $commandMap = $this->getServer()->getCommandMap();
        
        $commandMap->register("ultimatefactions", new FactionCommand($this));
        $commandMap->register("ultimatefactionsadmin", new FactionAdminCommand($this));
    }
    
    private function registerListeners(): void {
        $pluginManager = $this->getServer()->getPluginManager();
        
        $pluginManager->registerEvents(new PlayerListener($this), $this);
        $pluginManager->registerEvents(new BlockListener($this), $this);
        $pluginManager->registerEvents(new EntityListener($this), $this);
        $pluginManager->registerEvents(new ChatListener($this), $this);
        
        // Register ScoreHud listener if ScoreHud is available
        if ($this->scoreHudManager !== null && $this->scoreHudManager->scoreHudExists()) {
            $pluginManager->registerEvents(new ScoreHudListener(), $this);
            $this->getLogger()->info(TextFormat::GREEN . "ScoreHud integration enabled!");
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
        foreach ($this->factionManager->getFactions() as $faction) {
            if ($faction->isFreeze()) {
                $freezeTime = $faction->getFreezeTime();
                $currentTime = time();
                
                if ($freezeTime <= $currentTime) {
                    $faction->setFreeze(false);
                    $faction->setFreezeTime(0);
                    
                    // Notify faction members
                    $message = $this->messageManager->getMessage("faction_raid_protection");
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
    
    private function updateChunkBorders(): void {
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
        $claim = $this->claimManager->getClaimAt($chunkX, $chunkZ, $world->getFolderName());
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
            $this->factionManager->saveAll();
            $this->playerManager->saveAll();
            $this->cooldownManager->save();
        } catch (Exception $e) {
            $this->getLogger()->warning("Failed to save data: " . $e->getMessage());
        }
    }
    
    public function reloadConfigs(): void {
        $this->configManager->reload();
        $this->messageManager->reload();
    }
    
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
    
    public function getConfigManager(): ConfigManager {
        return $this->configManager;
    }
    
    public function getMessageManager(): MessageManager {
        return $this->messageManager;
    }
    
    public function getFactionManager(): FactionManager {
        return $this->factionManager;
    }
    
    public function getPlayerManager(): PlayerManager {
        return $this->playerManager;
    }
    
    public function getClaimManager(): ClaimManager {
        return $this->claimManager;
    }
    
    public function getPowerManager(): PowerManager {
        return $this->powerManager;
    }
    
    public function getCooldownManager(): CooldownManager {
        return $this->cooldownManager;
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
}