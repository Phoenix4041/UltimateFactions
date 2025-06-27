<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\player;

use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\provider\DataBase;
use Phoenix\ultimatefactions\faction\Faction;

class FactionPlayer {

    // Chat modes
    public const CHAT_GLOBAL = 0;
    public const CHAT_FACTION = 1;
    public const CHAT_ALLIANCE = 2;

    // Roles
    public const MEMBER = "member";
    public const OFFICER = "officer";
    public const LEADER = "leader";

    // Player data
    private string $name;
    private ?string $faction;
    private string $role;
    private int $chatMode;
    
    // Power system
    private float $power;
    private float $maxPower;
    
    // Statistics
    private int $kills;
    private int $deaths;
    private int $joinTime;
    private int $lastSeen;
    
    // Settings
    private bool $bypassMode;
    private bool $autoClaimMode;
    private bool $showBorders;
    private array $notifications;

    public function __construct(
        string $name, 
        ?string $faction = null, 
        string $role = self::MEMBER,
        float $power = 0.0,
        float $maxPower = 0.0,
        int $kills = 0,
        int $deaths = 0,
        int $joinTime = 0,
        int $lastSeen = 0,
        int $chatMode = self::CHAT_GLOBAL
    ) {
        $this->name = $name;
        $this->faction = $faction;
        $this->role = $role;
        $this->chatMode = $chatMode;
        
        // Initialize power system
        $config = Main::getInstance()->getConfigManager();
        $this->power = $power > 0 ? $power : $config->getDefaultPower();
        $this->maxPower = $maxPower > 0 ? $maxPower : $config->getMaxPower();
        
        // Initialize statistics
        $this->kills = $kills;
        $this->deaths = $deaths;
        $this->joinTime = $joinTime > 0 ? $joinTime : time();
        $this->lastSeen = $lastSeen > 0 ? $lastSeen : time();
        
        // Initialize settings
        $this->bypassMode = false;
        $this->autoClaimMode = false;
        $this->showBorders = false;
        $this->notifications = [];
    }

    // Basic getters and setters
    public function getName(): string {
        return $this->name;
    }

    public function getInstance(): ?Player {
        return Server::getInstance()->getPlayerExact($this->name);
    }

    public function isOnline(): bool {
        $player = $this->getInstance();
        return $player !== null && $player->isOnline();
    }

    // Faction management
    public function inFaction(): bool {
        return $this->faction !== null;
    }

    public function getFaction(): ?string {
        return $this->faction;
    }

    public function getFactionObject(): ?Faction {
        if ($this->faction === null) {
            return null;
        }
        return Main::getInstance()->getFactionManager()->getFactionByName($this->faction);
    }

    public function setFaction(?string $factionName): void {
        $oldFaction = $this->faction;
        $this->faction = $factionName;
        
        // Reset role if leaving faction
        if ($factionName === null) {
            $this->role = self::MEMBER;
            $this->chatMode = self::CHAT_GLOBAL;
        }
        
        // Log the change
        if ($oldFaction !== null && $factionName === null) {
            DataBase::addFactionLog($oldFaction, "LEAVE", $this->name, "Player left the faction");
        } elseif ($oldFaction === null && $factionName !== null) {
            DataBase::addFactionLog($factionName, "JOIN", $this->name, "Player joined the faction");
        } elseif ($oldFaction !== null && $factionName !== null && $oldFaction !== $factionName) {
            DataBase::addFactionLog($oldFaction, "LEAVE", $this->name, "Player left for another faction");
            DataBase::addFactionLog($factionName, "JOIN", $this->name, "Player joined from another faction");
        }
        
        $this->update();
    }

    // Role management
    public function getRole(): string {
        return $this->role;
    }

    public function setRole(string $role): void {
        $oldRole = $this->role;
        $this->role = $role;
        
        // Log role change
        if ($this->faction !== null && $oldRole !== $role) {
            DataBase::addFactionLog($this->faction, "ROLE_CHANGE", $this->name, "Role changed from $oldRole to $role");
        }
        
        $this->update();
    }

    public function isLeader(): bool {
        return $this->role === self::LEADER;
    }

    public function isOfficer(): bool {
        return $this->role === self::OFFICER;
    }

    public function isMember(): bool {
        return $this->role === self::MEMBER;
    }

    public function canManage(): bool {
        return $this->isLeader() || $this->isOfficer();
    }

    public function promote(): bool {
        if ($this->role === self::MEMBER) {
            $this->setRole(self::OFFICER);
            return true;
        }
        return false;
    }

    public function demote(): bool {
        if ($this->role === self::OFFICER) {
            $this->setRole(self::MEMBER);
            return true;
        }
        return false;
    }

    public function transfer(): void {
        $this->setRole(self::LEADER);
    }

    // Chat system
    public function getChatMode(): int {
        return $this->chatMode;
    }

    public function setChatMode(int $mode): void {
        $this->chatMode = $mode;
    }

    public function getChatModeString(): string {
        return match($this->chatMode) {
            self::CHAT_FACTION => "faction",
            self::CHAT_ALLIANCE => "alliance", 
            default => "global"
        };
    }

    public function toggleChatMode(): void {
        $this->chatMode = match($this->chatMode) {
            self::CHAT_GLOBAL => self::CHAT_FACTION,
            self::CHAT_FACTION => $this->canUseAllianceChat() ? self::CHAT_ALLIANCE : self::CHAT_GLOBAL,
            self::CHAT_ALLIANCE => self::CHAT_GLOBAL,
            default => self::CHAT_GLOBAL
        };
    }

    public function canUseAllianceChat(): bool {
        if (!$this->inFaction()) return false;
        $faction = $this->getFactionObject();
        return $faction !== null && !empty($faction->getAllies());
    }

    // Power system
    public function getPower(): float {
        return $this->power;
    }

    public function setPower(float $power): void {
        $this->power = max(0, min($power, $this->maxPower));
        $this->update();
    }

    public function addPower(float $amount): void {
        $this->setPower($this->power + $amount);
    }

    public function removePower(float $amount): void {
        $this->setPower($this->power - $amount);
    }

    public function getMaxPower(): float {
        return $this->maxPower;
    }

    public function setMaxPower(float $maxPower): void {
        $this->maxPower = $maxPower;
        // Adjust current power if it exceeds new maximum
        if ($this->power > $this->maxPower) {
            $this->power = $this->maxPower;
        }
        $this->update();
    }

    public function getPowerPercentage(): float {
        if ($this->maxPower <= 0) return 0.0;
        return ($this->power / $this->maxPower) * 100;
    }

    public function hasPower(): bool {
        return $this->power > 0;
    }

    // Statistics
    public function getKills(): int {
        return $this->kills;
    }

    public function addKill(): void {
        $this->kills++;
        $powerConfig = Main::getInstance()->getConfigManager()->getPowerConfig();
        $powerGain = $powerConfig["power_per_kill"] ?? 5;
        $this->addPower($powerGain);
        
        if ($this->faction !== null) {
            DataBase::addFactionLog($this->faction, "KILL", $this->name, "Player got a kill (+$powerGain power)");
        }
        
        $this->update();
    }

    public function getDeaths(): int {
        return $this->deaths;
    }

    public function addDeath(): void {
        $this->deaths++;
        $powerConfig = Main::getInstance()->getConfigManager()->getPowerConfig();
        $powerLoss = $powerConfig["power_per_death"] ?? 10;
        $this->removePower($powerLoss);
        
        if ($this->faction !== null) {
            DataBase::addFactionLog($this->faction, "DEATH", $this->name, "Player died (-$powerLoss power)");
        }
        
        $this->update();
    }

    public function getKDRatio(): float {
        if ($this->deaths === 0) return $this->kills > 0 ? $this->kills : 0.0;
        return round($this->kills / $this->deaths, 2);
    }

    public function getJoinTime(): int {
        return $this->joinTime;
    }

    public function getLastSeen(): int {
        return $this->lastSeen;
    }

    public function updateLastSeen(): void {
        $this->lastSeen = time();
        $this->update();
    }

    public function getTimeSinceJoin(): int {
        return time() - $this->joinTime;
    }

    public function getTimeSinceLastSeen(): int {
        return time() - $this->lastSeen;
    }

    public function getPlayTime(): string {
        $seconds = $this->getTimeSinceJoin();
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes}m";
        }
    }

    // Settings and modes
    public function isBypassMode(): bool {
        return $this->bypassMode;
    }

    public function setBypassMode(bool $bypass): void {
        $this->bypassMode = $bypass;
    }

    public function toggleBypassMode(): bool {
        $this->bypassMode = !$this->bypassMode;
        return $this->bypassMode;
    }

    public function isAutoClaimMode(): bool {
        return $this->autoClaimMode;
    }

    public function setAutoClaimMode(bool $autoClaim): void {
        $this->autoClaimMode = $autoClaim;
    }

    public function toggleAutoClaimMode(): bool {
        $this->autoClaimMode = !$this->autoClaimMode;
        return $this->autoClaimMode;
    }

    public function isShowBorders(): bool {
        return $this->showBorders;
    }

    public function setShowBorders(bool $showBorders): void {
        $this->showBorders = $showBorders;
        
        $main = Main::getInstance();
        if ($showBorders) {
            $main->enableChunkBorder($this->name);
        } else {
            $main->disableChunkBorder($this->name);
        }
    }

    public function toggleShowBorders(): bool {
        $this->setShowBorders(!$this->showBorders);
        return $this->showBorders;
    }

    // Notifications
    public function addNotification(string $type, string $message, int $expireTime = 0): void {
        $this->notifications[] = [
            'type' => $type,
            'message' => $message,
            'time' => time(),
            'expire' => $expireTime > 0 ? time() + $expireTime : 0
        ];
    }

    public function getNotifications(): array {
        // Clean expired notifications
        $currentTime = time();
        $this->notifications = array_filter($this->notifications, function($notification) use ($currentTime) {
            return $notification['expire'] === 0 || $notification['expire'] > $currentTime;
        });
        
        return $this->notifications;
    }

    public function clearNotifications(): void {
        $this->notifications = [];
    }

    public function hasUnreadNotifications(): bool {
        return !empty($this->getNotifications());
    }

    // Utility methods
    public function kick(): void {
        $oldFaction = $this->faction;
        $this->faction = null;
        $this->role = self::MEMBER;
        $this->chatMode = self::CHAT_GLOBAL;
        
        if ($oldFaction !== null) {
            DataBase::addFactionLog($oldFaction, "KICK", $this->name, "Player was kicked from faction");
        }
        
        $this->update();
    }

    public function sendMessage(string $message): void {
        $player = $this->getInstance();
        if ($player !== null) {
            $player->sendMessage($message);
        }
    }

    public function sendFormattedMessage(string $key, array $params = []): void {
        $message = Main::getInstance()->getMessageManager()->getMessage($key, $params);
        $this->sendMessage($message);
    }

    public function hasPermission(string $permission): bool {
        $player = $this->getInstance();
        if ($player === null) return false;
        
        return $player->hasPermission($permission);
    }

    public function canBypass(): bool {
        return $this->bypassMode && $this->hasPermission("ultimatefactions.bypass");
    }

    public function canInteractWithFaction(Faction $faction): bool {
        if ($this->canBypass()) return true;
        if (!$this->inFaction()) return false;
        
        $playerFaction = $this->getFactionObject();
        if ($playerFaction === null) return false;
        
        // Own faction
        if ($playerFaction->getName() === $faction->getName()) return true;
        
        // Allied faction
        if ($playerFaction->isAlly($faction->getName())) return true;
        
        return false;
    }

    public function getDisplayName(): string {
        $player = $this->getInstance();
        if ($player !== null) {
            return $player->getDisplayName();
        }
        return $this->name;
    }

    public function getNameTag(): string {
        $nameTag = $this->getDisplayName();
        
        if ($this->inFaction()) {
            $faction = $this->getFactionObject();
            if ($faction !== null) {
                $factionTag = $faction->getDisplayName();
                $roleColor = match($this->role) {
                    self::LEADER => TextFormat::RED,
                    self::OFFICER => TextFormat::YELLOW,
                    default => TextFormat::GREEN
                };
                
                $nameTag = TextFormat::WHITE . "[" . TextFormat::AQUA . $factionTag . TextFormat::WHITE . "] " . 
                          $roleColor . $nameTag;
            }
        }
        
        return $nameTag;
    }

    public function isInSameFaction(FactionPlayer $other): bool {
        return $this->inFaction() && 
               $other->inFaction() && 
               $this->faction === $other->getFaction();
    }

    public function isAllyWith(FactionPlayer $other): bool {
        if (!$this->inFaction() || !$other->inFaction()) return false;
        
        $myFaction = $this->getFactionObject();
        $otherFaction = $other->getFactionObject();
        
        if ($myFaction === null || $otherFaction === null) return false;
        
        return $myFaction->isAlly($otherFaction->getName());
    }

    public function canAttack(FactionPlayer $other): bool {
        // Can't attack same faction members
        if ($this->isInSameFaction($other)) return false;
        
        // Can't attack allies
        if ($this->isAllyWith($other)) return false;
        
        // Check if other player's faction is peaceful
        $otherFaction = $other->getFactionObject();
        if ($otherFaction !== null && $otherFaction->isPeaceful()) return false;
        
        // Check if own faction is peaceful
        $myFaction = $this->getFactionObject();
        if ($myFaction !== null && $myFaction->isPeaceful()) return false;
        
        return true;
    }

    // Data persistence
    public function update(): void {
        DataBase::updatePlayerSession($this);
    }

    public function save(): void {
        $this->update();
    }

    public function delete(): void {
        // Remove from faction if in one
        if ($this->inFaction()) {
            $faction = $this->getFactionObject();
            if ($faction !== null) {
                $faction->removeMember($this->name);
            }
        }
        
        // Remove border display
        Main::getInstance()->removePlayerFromBorderList($this->name);
        
        // Clear notifications
        $this->clearNotifications();
    }

    // Array conversion for serialization
    public function toArray(): array {
        return [
            'name' => $this->name,
            'faction' => $this->faction,
            'role' => $this->role,
            'chatMode' => $this->chatMode,
            'power' => $this->power,
            'maxPower' => $this->maxPower,
            'kills' => $this->kills,
            'deaths' => $this->deaths,
            'joinTime' => $this->joinTime,
            'lastSeen' => $this->lastSeen,
            'bypassMode' => $this->bypassMode,
            'autoClaimMode' => $this->autoClaimMode,
            'showBorders' => $this->showBorders,
            'notifications' => $this->notifications
        ];
    }

    public static function fromArray(array $data): FactionPlayer {
        $player = new self(
            $data['name'],
            $data['faction'] ?? null,
            $data['role'] ?? self::MEMBER,
            $data['power'] ?? 0.0,
            $data['maxPower'] ?? 0.0,
            $data['kills'] ?? 0,
            $data['deaths'] ?? 0,
            $data['joinTime'] ?? time(),
            $data['lastSeen'] ?? time(),
            $data['chatMode'] ?? self::CHAT_GLOBAL
        );
        
        $player->bypassMode = $data['bypassMode'] ?? false;
        $player->autoClaimMode = $data['autoClaimMode'] ?? false;
        $player->showBorders = $data['showBorders'] ?? false;
        $player->notifications = $data['notifications'] ?? [];
        
        return $player;
    }
}