<?php

namespace Phoenix\ultimatefactions\addons\scorehud;

use Ifera\ScoreHud\event\TagsResolveEvent;
use pocketmine\event\Listener;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\addons\scorehud\ScoreHudTags;

class ScoreHudListener implements Listener
{

    public function onTagResolve(TagsResolveEvent $event): void
    {
        $player = $event->getPlayer();
        $playerManager = Main::getInstance()->getPlayerManager();
        $factionManager = Main::getInstance()->getFactionManager();
        
        // Get player faction data
        $playerFaction = $playerManager->getPlayerFaction($player);
        $faction = null;
        $playerRole = "N/A";
        
        if ($playerFaction !== null) {
            $faction = $factionManager->getFactionByName($playerFaction->getName());
            $playerData = $playerManager->getPlayerData($player);
            $playerRole = $playerData?->getRole() ?? "Member";
        }
        
        $tag = $event->getTag();
        switch ($tag->getName()) {
            case ScoreHudTags::FACTION:
                $tag->setValue($faction === null ? "N/A" : $faction->getName());
                break;
                
            case ScoreHudTags::FACTION_RANK:
                $tag->setValue($playerRole);
                break;
                
            case ScoreHudTags::FACTION_POWER:
                if ($faction === null) {
                    $tag->setValue("N/A");
                } else {
                    $power = $faction->getPower();
                    $tag->setValue((string)round($power, 2, PHP_ROUND_HALF_DOWN));
                }
                break;
                
            case ScoreHudTags::FACTION_FREEZE_TIME:
                if ($faction === null || !$faction->isFreeze()) {
                    $tag->setValue("N/A");
                } else {
                    $freezeTime = $faction->getFreezeTime();
                    $currentTime = time();
                    $remainingTime = max(0, $freezeTime - $currentTime);
                    
                    if ($remainingTime > 0) {
                        $minutes = floor($remainingTime / 60);
                        $seconds = $remainingTime % 60;
                        $tag->setValue($minutes . "m " . $seconds . "s");
                    } else {
                        $tag->setValue("N/A");
                    }
                }
                break;
                
            case ScoreHudTags::FACTION_MEMBERS:
                $tag->setValue($faction === null ? "N/A" : (string)count($faction->getMembers()));
                break;
                
            case ScoreHudTags::FACTION_CLAIMS:
                if ($faction === null) {
                    $tag->setValue("N/A");
                } else {
                    $claimManager = Main::getInstance()->getClaimManager();
                    $claims = $claimManager->getFactionClaims($faction->getName());
                    $tag->setValue((string)count($claims));
                }
                break;
                
            case ScoreHudTags::FACTION_ALLIES:
                $tag->setValue($faction === null ? "N/A" : (string)count($faction->getAllies()));
                break;
                
            case ScoreHudTags::PLAYER_POWER:
                $powerManager = Main::getInstance()->getPowerManager();
                $playerPower = $powerManager->getPlayerPower($player);
                $tag->setValue((string)round($playerPower, 2, PHP_ROUND_HALF_DOWN));
                break;
        }
    }
}