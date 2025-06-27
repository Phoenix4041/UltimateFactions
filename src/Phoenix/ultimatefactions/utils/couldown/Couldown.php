<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\utils;

use pocketmine\utils\Config;
use Phoenix\ultimatefactions\Main;

class Cooldown {
    
    private array $cooldowns = [];
    private Config $data;
    private Main $plugin;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->data = new Config($plugin->getDataFolder() . "cooldown.yml", Config::YAML);
        
        // Load existing cooldowns
        foreach ($this->data->getAll() as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $type => $time) {
                    $this->cooldowns[$name][$type] = $time;
                    
                    // Clean expired cooldowns on load
                    if (!$this->hasCooldown($name, $type)) {
                        $this->removeCooldown($name, $type);
                    }
                }
            }
        }
    }
    
    public function addCooldown(string $name, string $type, int $duration): void {
        $this->cooldowns[$name][$type] = time() + $duration;
    }
    
    public function removeCooldown(string $name, string $type): void {
        if (isset($this->cooldowns[$name][$type])) {
            unset($this->cooldowns[$name][$type]);
            
            // Remove the player entry if no cooldowns remain
            if (empty($this->cooldowns[$name])) {
                unset($this->cooldowns[$name]);
            }
        }
    }
    
    public function hasCooldown(string $name, string $type): bool {
        if (isset($this->cooldowns[$name][$type])) {
            if (time() < $this->cooldowns[$name][$type]) {
                return true;
            } else {
                // Auto-remove expired cooldown
                $this->removeCooldown($name, $type);
            }
        }
        
        return false;
    }
    
    public function getCooldown(string $name, string $type): int {
        if (!$this->hasCooldown($name, $type)) {
            return 0;
        }
        
        return $this->cooldowns[$name][$type] - time();
    }
    
    public function getCooldownTime(string $name, string $type): int {
        return $this->cooldowns[$name][$type] ?? 0;
    }
    
    public function getAllCooldowns(string $name): array {
        return $this->cooldowns[$name] ?? [];
    }
    
    public function clearAllCooldowns(string $name): void {
        if (isset($this->cooldowns[$name])) {
            unset($this->cooldowns[$name]);
        }
    }
    
    public function clearExpiredCooldowns(): void {
        foreach ($this->cooldowns as $name => $cooldownTypes) {
            foreach ($cooldownTypes as $type => $time) {
                if (time() >= $time) {
                    $this->removeCooldown($name, $type);
                }
            }
        }
    }
    
    public function formatCooldownTime(string $name, string $type): string {
        $remainingTime = $this->getCooldown($name, $type);
        return Utils::formatTime($remainingTime);
    }
    
    public function intToTimeString(int $seconds): string {
        return Utils::formatTime($seconds);
    }
    
    public function save(): void {
        // Clean expired cooldowns before saving
        $this->clearExpiredCooldowns();
        
        $this->data->setAll($this->cooldowns);
        $this->data->save();
    }
    
    // Predefined cooldown types
    public const TELEPORT = "teleport";
    public const HOME = "home";
    public const WARP = "warp";
    public const FACTION_CREATE = "faction_create";
    public const FACTION_DISBAND = "faction_disband";
    public const FACTION_LEAVE = "faction_leave";
    public const FACTION_JOIN = "faction_join";
    public const FACTION_KICK = "faction_kick";
    public const FACTION_CLAIM = "faction_claim";
    public const FACTION_UNCLAIM = "faction_unclaim";
    public const FACTION_INVITE = "faction_invite";
    public const FACTION_ALLY = "faction_ally";
    public const FACTION_ENEMY = "faction_enemy";
    public const FACTION_NEUTRAL = "faction_neutral";
    public const FACTION_CHAT = "faction_chat";
    public const FACTION_FLY = "faction_fly";
    public const FACTION_MAP = "faction_map";
    public const FACTION_SETHOME = "faction_sethome";
    public const FACTION_UNSETHOME = "faction_unsethome";
    public const FACTION_PROMOTE = "faction_promote";
    public const FACTION_DEMOTE = "faction_demote";
    public const FACTION_TRANSFER = "faction_transfer";
    public const FACTION_DESCRIPTION = "faction_description";
    public const FACTION_TITLE = "faction_title";
    public const FACTION_OPEN = "faction_open";
    public const FACTION_CLOSE = "faction_close";
    public const FACTION_SHOW = "faction_show";
    public const FACTION_TOP = "faction_top";
    public const FACTION_LIST = "faction_list";
    public const FACTION_WHO = "faction_who";
    public const FACTION_HELP = "faction_help";
    public const FACTION_VERSION = "faction_version";
    public const FACTION_RELOAD = "faction_reload";
    public const FACTION_SAVE = "faction_save";
}