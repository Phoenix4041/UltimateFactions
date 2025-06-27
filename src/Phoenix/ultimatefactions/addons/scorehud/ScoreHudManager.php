<?php

namespace Phoenix\ultimatefactions\addons\scorehud;

use Ifera\ScoreHud\event\PlayerTagsUpdateEvent;
use Ifera\ScoreHud\event\PlayerTagUpdateEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\addons\scorehud\ScoreHudTags;

class ScoreHudManager
{

    use SingletonTrait;

    public function scoreHudExists(): bool
    {
        $scorehud = Server::getInstance()->getPluginManager()->getPlugin("ScoreHud");
        if ($scorehud === null) {
            return false;
        }
        
        $version = $scorehud->getDescription()->getVersion();
        if (version_compare($version, "6.0.0") <= 0) {
            return false;
        }
        
        if (version_compare($version, "6.1.0") < 0) {
            Main::getInstance()->getLogger()->warning("Outdated version of ScoreHud (v" . $version . ") detected, requires >= v6.1.0. Integration disabled.");
            return false;
        }

        return true;
    }

    public function updateAllPlayerTags(Player $player): void
    {
        if (!$this->scoreHudExists()) return;
        
        $playerManager = Main::getInstance()->getPlayerManager();
        $factionManager = Main::getInstance()->getFactionManager();
        $powerManager = Main::getInstance()->getPowerManager();
        $claimManager = Main::getInstance()->getClaimManager();
        
        $playerFaction = $playerManager->getPlayerFaction($player);
        $faction = null;
        $playerRole = "N/A";
        $factionPower = "N/A";
        $factionMembers = "N/A";
        $factionClaims = "N/A";
        $factionAllies = "N/A";
        $freezeTime = "N/A";
        
        if ($playerFaction !== null) {
            $faction = $factionManager->getFactionByName($playerFaction->getName());
            $playerData = $playerManager->getPlayerData($player);
            $playerRole = $playerData?->getRole() ?? "Member";
            
            if ($faction !== null) {
                $factionPower = (string)round($faction->getPower(), 2, PHP_ROUND_HALF_DOWN);
                $factionMembers = (string)count($faction->getMembers());
                $factionAllies = (string)count($faction->getAllies());
                
                $claims = $claimManager->getFactionClaims($faction->getName());
                $factionClaims = (string)count($claims);
                
                if ($faction->isFreeze()) {
                    $freezeTimeStamp = $faction->getFreezeTime();
                    $currentTime = time();
                    $remainingTime = max(0, $freezeTimeStamp - $currentTime);
                    
                    if ($remainingTime > 0) {
                        $minutes = floor($remainingTime / 60);
                        $seconds = $remainingTime % 60;
                        $freezeTime = $minutes . "m " . $seconds . "s";
                    }
                }
            }
        }
        
        $playerPower = (string)round($powerManager->getPlayerPower($player), 2, PHP_ROUND_HALF_DOWN);
        
        (new PlayerTagsUpdateEvent($player, [
            new ScoreTag(ScoreHudTags::FACTION, $faction?->getName() ?? "N/A"),
            new ScoreTag(ScoreHudTags::FACTION_RANK, $playerRole),
            new ScoreTag(ScoreHudTags::FACTION_POWER, $factionPower),
            new ScoreTag(ScoreHudTags::FACTION_FREEZE_TIME, $freezeTime),
            new ScoreTag(ScoreHudTags::FACTION_MEMBERS, $factionMembers),
            new ScoreTag(ScoreHudTags::FACTION_CLAIMS, $factionClaims),
            new ScoreTag(ScoreHudTags::FACTION_ALLIES, $factionAllies),
            new ScoreTag(ScoreHudTags::PLAYER_POWER, $playerPower)
        ]))->call();
    }

    public function updatePlayerFactionTag(Player $player, string|null $faction = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION, $faction ?? "N/A")))->call();
    }

    public function updatePlayerFactionRankTag(Player $player, string|null $rank = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION_RANK, $rank ?? "N/A")))->call();
    }

    public function updatePlayerFactionPowerTag(Player $player, float|null $power = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        $powerValue = $power === null ? "N/A" : (string)round($power, 2, PHP_ROUND_HALF_DOWN);
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION_POWER, $powerValue)))->call();
    }

    public function updatePlayerFactionFreezeTimeTag(Player $player, int|null $freezeTime = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        $freezeValue = "N/A";
        if ($freezeTime !== null && $freezeTime > time()) {
            $remainingTime = $freezeTime - time();
            $minutes = floor($remainingTime / 60);
            $seconds = $remainingTime % 60;
            $freezeValue = $minutes . "m " . $seconds . "s";
        }
        
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION_FREEZE_TIME, $freezeValue)))->call();
    }
    
    public function updatePlayerFactionMembersTag(Player $player, int|null $memberCount = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        $memberValue = $memberCount === null ? "N/A" : (string)$memberCount;
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION_MEMBERS, $memberValue)))->call();
    }
    
    public function updatePlayerFactionClaimsTag(Player $player, int|null $claimCount = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        $claimValue = $claimCount === null ? "N/A" : (string)$claimCount;
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION_CLAIMS, $claimValue)))->call();
    }
    
    public function updatePlayerFactionAlliesTag(Player $player, int|null $allyCount = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        $allyValue = $allyCount === null ? "N/A" : (string)$allyCount;
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::FACTION_ALLIES, $allyValue)))->call();
    }
    
    public function updatePlayerPowerTag(Player $player, float|null $power = null): void
    {
        if (!$this->scoreHudExists()) return;
        
        $powerValue = $power === null ? "N/A" : (string)round($power, 2, PHP_ROUND_HALF_DOWN);
        (new PlayerTagUpdateEvent($player, new ScoreTag(ScoreHudTags::PLAYER_POWER, $powerValue)))->call();
    }
}