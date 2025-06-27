<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\listeners;

use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\Listener;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\objects\Faction;

class EntityListener implements Listener {
    
    private Main $plugin;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function onEntityDamage(EntityDamageEvent $event): void {
        if ($event->isCancelled()) {
            return;
        }
        
        $entity = $event->getEntity();
        
        // Only handle player damage
        if (!$entity instanceof Player) {
            return;
        }
        
        $victim = $entity;
        
        // Check if world allows faction interactions
        if (!$this->isValidFactionWorld($victim->getWorld()->getFolderName())) {
            return;
        }
        
        // Handle different damage types
        if ($event instanceof EntityDamageByChildEntityEvent) {
            $this->handleChildEntityDamage($event, $victim);
        } elseif ($event instanceof EntityDamageByEntityEvent) {
            $this->handleEntityDamage($event, $victim);
        }
    }
    
    private function handleChildEntityDamage(EntityDamageByChildEntityEvent $event, Player $victim): void {
        $damager = $event->getDamager();
        $child = $event->getChild();
        
        if (!$damager instanceof Player) {
            return;
        }
        
        // Check if players are allied
        if (!$this->plugin->getPlayerManager()->areAllied($victim, $damager)) {
            return;
        }
        
        // Cancel damage between allies
        $event->cancel();
        
        // Return arrow if it was a projectile
        if ($child instanceof Arrow) {
            $damager->getInventory()->addItem(VanillaItems::ARROW());
        }
        
        // Send message to both players
        if (!$this->plugin->getCooldownManager()->hasCooldown($damager->getName(), "ally_damage_message")) {
            $message = $this->plugin->getMessageManager()->getMessage("cannot_damage_ally", [
                "{PLAYER}" => $victim->getName()
            ]);
            $damager->sendMessage($message);
            
            $this->plugin->getCooldownManager()->setCooldown(
                $damager->getName(), 
                "ally_damage_message", 
                2 // 2 seconds cooldown
            );
        }
    }
    
    private function handleEntityDamage(EntityDamageByEntityEvent $event, Player $victim): void {
        $damager = $event->getDamager();
        
        if (!$damager instanceof Player) {
            return;
        }
        
        // Check if players are allied
        if (!$this->plugin->getPlayerManager()->areAllied($victim, $damager)) {
            return;
        }
        
        // Cancel damage between allies
        $event->cancel();
        
        // Send message to damager
        if (!$this->plugin->getCooldownManager()->hasCooldown($damager->getName(), "ally_damage_message")) {
            $message = $this->plugin->getMessageManager()->getMessage("cannot_damage_ally", [
                "{PLAYER}" => $victim->getName()
            ]);
            $damager->sendMessage($message);
            
            $this->plugin->getCooldownManager()->setCooldown(
                $damager->getName(), 
                "ally_damage_message", 
                2 // 2 seconds cooldown
            );
        }
    }
    
    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        
        // Only handle player deaths
        if (!$entity instanceof Player) {
            return;
        }
        
        $victim = $entity;
        
        // Check if world allows faction interactions
        if (!$this->isValidFactionWorld($victim->getWorld()->getFolderName())) {
            return;
        }
        
        $lastDamage = $victim->getLastDamageCause();
        if (!$lastDamage instanceof EntityDamageByEntityEvent) {
            return;
        }
        
        $damager = $lastDamage->getDamager();
        if (!$damager instanceof Player) {
            return;
        }
        
        // Handle kill/death logic
        $this->handlePlayerKill($damager, $victim);
    }
    
    private function handlePlayerKill(Player $killer, Player $victim): void {
        $killerFaction = $this->plugin->getPlayerManager()->getPlayerFaction($killer);
        $victimFaction = $this->plugin->getPlayerManager()->getPlayerFaction($victim);
        
        // Update player statistics
        $this->plugin->getPlayerManager()->handlePlayerKill($killer->getName(), $victim->getName());
        
        // Update faction statistics if applicable
        if ($killerFaction !== null) {
            $killerFaction->addKill();
            $this->plugin->getFactionManager()->updateFaction($killerFaction);
            
            // Broadcast kill to faction members
            $message = $this->plugin->getMessageManager()->getMessage("faction_member_kill", [
                "{KILLER}" => $killer->getName(),
                "{VICTIM}" => $victim->getName()
            ]);
            $killerFaction->broadcastMessage($message);
        }
        
        if ($victimFaction !== null) {
            $victimFaction->addDeath();
            $this->plugin->getFactionManager()->updateFaction($victimFaction);
            
            // Check if faction should be frozen after losing power
            $this->checkFactionFreeze($victimFaction);
            
            // Broadcast death to faction members
            $message = $this->plugin->getMessageManager()->getMessage("faction_member_death", [
                "{VICTIM}" => $victim->getName(),
                "{KILLER}" => $killer->getName()
            ]);
            $victimFaction->broadcastMessage($message);
        }
        
        // Send kill/death messages
        $killMessage = $this->plugin->getMessageManager()->getMessage("player_kill", [
            "{VICTIM}" => $victim->getName(),
            "{POWER}" => $this->plugin->getConfigManager()->getPowerPerKill()
        ]);
        $killer->sendMessage($killMessage);
        
        $deathMessage = $this->plugin->getMessageManager()->getMessage("player_death", [
            "{KILLER}" => $killer->getName(),
            "{POWER}" => $this->plugin->getConfigManager()->getPowerPerDeath()
        ]);
        $victim->sendMessage($deathMessage);
    }
    
    private function checkFactionFreeze(Faction $faction): void {
        $totalPower = $this->plugin->getPlayerManager()->getTotalFactionPower($faction->getName());
        $claimsCount = $this->plugin->getClaimManager()->getFactionClaimsCount($faction->getName());
        
        // Check if faction has negative power compared to claims
        if ($totalPower < $claimsCount * $this->plugin->getConfigManager()->getPowerPerClaim()) {
            $freezeTime = $this->plugin->getConfigManager()->getFreezeTime();
            
            if (!$faction->isFreeze()) {
                $faction->setFreeze(true);
                $faction->setFreezeTime(time() + $freezeTime);
                
                $this->plugin->getFactionManager()->updateFaction($faction);
                
                // Notify faction members
                $message = $this->plugin->getMessageManager()->getMessage("faction_under_raid", [
                    "{TIME}" => gmdate("H:i:s", $freezeTime)
                ]);
                $faction->broadcastMessage($message);
            }
        }
    }
    
    /**
     * Check if the world allows faction interactions
     */
    private function isValidFactionWorld(string $worldName): bool {
        $factionWorlds = $this->plugin->getConfigManager()->getFactionWorlds();
        return empty($factionWorlds) || in_array($worldName, $factionWorlds, true);
    }
}