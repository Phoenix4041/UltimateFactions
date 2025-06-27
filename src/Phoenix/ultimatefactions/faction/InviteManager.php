<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\managers;

use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\objects\Faction;
use Phoenix\ultimatefactions\objects\FactionInvite;
use Phoenix\ultimatefactions\events\FactionInviteEvent;
use Phoenix\ultimatefactions\events\FactionInviteAcceptEvent;
use Phoenix\ultimatefactions\events\FactionInviteDeclineEvent;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use Exception;

class InviteManager {
    
    private Main $plugin;
    private array $invites = [];
    private array $cachedInvites = [];
    private bool $loaded = false;
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function init(): void {
        $this->loadInvites();
        $this->startCleanupTask();
    }
    
    private function loadInvites(): void {
        $this->plugin->getDatabase()->executeSelect(
            "data.getActiveInvites",
            [],
            function (array $rows): void {
                foreach ($rows as $row) {
                    $invite = new FactionInvite(
                        $row["player_name"],
                        $row["faction_name"],
                        $row["inviter"],
                        (int) $row["created_at"],
                        (int) $row["expires_at"]
                    );
                    
                    $this->invites[$row["player_name"]] = $invite;
                }
                
                $this->loaded = true;
                $this->plugin->getLogger()->debug(
                    "Loaded " . count($this->invites) . " active faction invites"
                );
            },
            function (\SqlError $error): void {
                $this->plugin->getLogger()->error("Failed to load invites: " . $error->getMessage());
            }
        );
    }
    
    public function sendInvite(Player $inviter, Player $target, Faction $faction): bool {
        // Check if player already has an invite from this faction
        if ($this->hasInviteFromFaction($target->getName(), $faction->getName())) {
            return false;
        }
        
        // Check if target is already in a faction
        $playerManager = $this->plugin->getPlayerManager();
        $targetFactionPlayer = $playerManager->getFactionPlayer($target);
        if ($targetFactionPlayer !== null && $targetFactionPlayer->getFaction() !== null) {
            return false;
        }
        
        // Check if inviter has permission to invite
        $inviterFactionPlayer = $playerManager->getFactionPlayer($inviter);
        if ($inviterFactionPlayer === null || 
            !$this->canInviteToFaction($inviterFactionPlayer, $faction)) {
            return false;
        }
        
        // Check faction member limits
        $maxMembers = $this->plugin->getConfigManager()->getMaxMembers();
        if (count($faction->getMembers()) >= $maxMembers && 
            !$inviter->hasPermission("ultimatefactions.members.unlimited")) {
            return false;
        }
        
        // Create invite
        $expirationTime = time() + $this->plugin->getConfigManager()->getInviteExpirationTime();
        $invite = new FactionInvite(
            $target->getName(),
            $faction->getName(),
            $inviter->getName(),
            time(),
            $expirationTime
        );
        
        // Fire event
        $event = new FactionInviteEvent($faction, $inviter, $target, $invite);
        $event->call();
        
        if ($event->isCancelled()) {
            return false;
        }
        
        // Save to database
        $this->plugin->getDatabase()->executeGeneric(
            "data.createFactionInvite",
            [
                "player_name" => $target->getName(),
                "faction_name" => $faction->getName(),
                "inviter" => $inviter->getName(),
                "created_at" => $invite->getCreatedAt(),
                "expires_at" => $invite->getExpiresAt()
            ]
        );
        
        // Store in memory
        $this->invites[$target->getName()] = $invite;
        
        // Send messages
        $messageManager = $this->plugin->getMessageManager();
        
        $target->sendMessage($messageManager->getMessage("faction_invite_received", [
            "{faction}" => $faction->getName(),
            "{inviter}" => $inviter->getName(),
            "{expires}" => $this->formatTimeRemaining($expirationTime - time())
        ]));
        
        $inviter->sendMessage($messageManager->getMessage("faction_invite_sent", [
            "{player}" => $target->getName(),
            "{faction}" => $faction->getName()
        ]));
        
        // Notify faction members
        $faction->broadcastMessage(
            $messageManager->getMessage("faction_invite_broadcast", [
                "{inviter}" => $inviter->getName(),
                "{player}" => $target->getName()
            ]),
            [$target->getName()] // Exclude target from broadcast
        );
        
        // Schedule expiration
        $this->scheduleInviteExpiration($invite);
        
        return true;
    }
    
    public function acceptInvite(Player $player): bool {
        $invite = $this->getInvite($player->getName());
        if ($invite === null || $this->isInviteExpired($invite)) {
            return false;
        }
        
        $faction = $this->plugin->getFactionManager()->getFactionByName($invite->getFactionName());
        if ($faction === null) {
            $this->removeInvite($player->getName());
            return false;
        }
        
        // Check if player is already in a faction
        $playerManager = $this->plugin->getPlayerManager();
        $factionPlayer = $playerManager->getFactionPlayer($player);
        if ($factionPlayer !== null && $factionPlayer->getFaction() !== null) {
            $this->removeInvite($player->getName());
            return false;
        }
        
        // Fire event
        $event = new FactionInviteAcceptEvent($faction, $player, $invite);
        $event->call();
        
        if ($event->isCancelled()) {
            return false;
        }
        
        // Add player to faction
        $factionManager = $this->plugin->getFactionManager();
        if (!$factionManager->addPlayerToFaction($player, $faction)) {
            return false;
        }
        
        // Remove invite
        $this->removeInvite($player->getName());
        
        // Send messages
        $messageManager = $this->plugin->getMessageManager();
        
        $player->sendMessage($messageManager->getMessage("faction_invite_accepted", [
            "{faction}" => $faction->getName()
        ]));
        
        $faction->broadcastMessage(
            $messageManager->getMessage("faction_player_joined", [
                "{player}" => $player->getName()
            ])
        );
        
        // Notify inviter if online
        $inviter = Server::getInstance()->getPlayerExact($invite->getInviter());
        if ($inviter instanceof Player) {
            $inviter->sendMessage($messageManager->getMessage("faction_invite_accepted_inviter", [
                "{player}" => $player->getName(),
                "{faction}" => $faction->getName()
            ]));
        }
        
        return true;
    }
    
    public function declineInvite(Player $player): bool {
        $invite = $this->getInvite($player->getName());
        if ($invite === null) {
            return false;
        }
        
        $faction = $this->plugin->getFactionManager()->getFactionByName($invite->getFactionName());
        
        // Fire event
        $event = new FactionInviteDeclineEvent($faction, $player, $invite);
        $event->call();
        
        if ($event->isCancelled()) {
            return false;
        }
        
        // Remove invite
        $this->removeInvite($player->getName());
        
        // Send messages
        $messageManager = $this->plugin->getMessageManager();
        
        $player->sendMessage($messageManager->getMessage("faction_invite_declined", [
            "{faction}" => $invite->getFactionName()
        ]));
        
        // Notify inviter if online
        $inviter = Server::getInstance()->getPlayerExact($invite->getInviter());
        if ($inviter instanceof Player) {
            $inviter->sendMessage($messageManager->getMessage("faction_invite_declined_inviter", [
                "{player}" => $player->getName(),
                "{faction}" => $invite->getFactionName()
            ]));
        }
        
        // Notify faction
        if ($faction !== null) {
            $faction->broadcastMessage(
                $messageManager->getMessage("faction_invite_declined_broadcast", [
                    "{player}" => $player->getName()
                ])
            );
        }
        
        return true;
    }
    
    public function revokeInvite(Player $revoker, string $targetName): bool {
        $invite = $this->getInvite($targetName);
        if ($invite === null) {
            return false;
        }
        
        // Check if revoker has permission
        $playerManager = $this->plugin->getPlayerManager();
        $revokerFactionPlayer = $playerManager->getFactionPlayer($revoker);
        $faction = $this->plugin->getFactionManager()->getFactionByName($invite->getFactionName());
        
        if ($revokerFactionPlayer === null || $faction === null || 
            !$this->canInviteToFaction($revokerFactionPlayer, $faction)) {
            return false;
        }
        
        // Remove invite
        $this->removeInvite($targetName);
        
        // Send messages
        $messageManager = $this->plugin->getMessageManager();
        
        $revoker->sendMessage($messageManager->getMessage("faction_invite_revoked", [
            "{player}" => $targetName,
            "{faction}" => $faction->getName()
        ]));
        
        // Notify target if online
        $target = Server::getInstance()->getPlayerExact($targetName);
        if ($target instanceof Player) {
            $target->sendMessage($messageManager->getMessage("faction_invite_revoked_target", [
                "{faction}" => $faction->getName(),
                "{revoker}" => $revoker->getName()
            ]));
        }
        
        return true;
    }
    
    public function hasInvite(string $playerName): bool {
        $invite = $this->getInvite($playerName);
        return $invite !== null && !$this->isInviteExpired($invite);
    }
    
    public function hasInviteFromFaction(string $playerName, string $factionName): bool {
        $invite = $this->getInvite($playerName);
        return $invite !== null && 
               $invite->getFactionName() === $factionName && 
               !$this->isInviteExpired($invite);
    }
    
    public function getInvite(string $playerName): ?FactionInvite {
        return $this->invites[$playerName] ?? null;
    }
    
    public function getPlayerInvites(string $playerName): array {
        $playerInvites = [];
        foreach ($this->invites as $invite) {
            if ($invite->getPlayerName() === $playerName && !$this->isInviteExpired($invite)) {
                $playerInvites[] = $invite;
            }
        }
        return $playerInvites;
    }
    
    public function getFactionInvites(Faction $faction): array {
        $factionInvites = [];
        foreach ($this->invites as $invite) {
            if ($invite->getFactionName() === $faction->getName() && !$this->isInviteExpired($invite)) {
                $factionInvites[] = $invite;
            }
        }
        return $factionInvites;
    }
    
    public function removeInvite(string $playerName): void {
        if (isset($this->invites[$playerName])) {
            $invite = $this->invites[$playerName];
            
            // Remove from database
            $this->plugin->getDatabase()->executeGeneric(
                "data.deleteFactionInvite",
                [
                    "player_name" => $playerName,
                    "faction_name" => $invite->getFactionName()
                ]
            );
            
            // Remove from memory
            unset($this->invites[$playerName]);
        }
    }
    
    public function removeAllFactionInvites(Faction $faction): void {
        foreach ($this->invites as $playerName => $invite) {
            if ($invite->getFactionName() === $faction->getName()) {
                $this->removeInvite($playerName);
            }
        }
    }
    
    private function isInviteExpired(FactionInvite $invite): bool {
        return time() >= $invite->getExpiresAt();
    }
    
    private function canInviteToFaction($factionPlayer, Faction $faction): bool {
        if ($factionPlayer->getFaction() !== $faction->getName()) {
            return false;
        }
        
        $role = $factionPlayer->getRole();
        return in_array($role, ["leader", "officer"]);
    }
    
    private function scheduleInviteExpiration(FactionInvite $invite): void {
        $delay = $invite->getExpiresAt() - time();
        if ($delay <= 0) {
            return;
        }
        
        $this->plugin->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($invite): void {
                if (isset($this->invites[$invite->getPlayerName()])) {
                    $currentInvite = $this->invites[$invite->getPlayerName()];
                    if ($currentInvite->getCreatedAt() === $invite->getCreatedAt()) {
                        $this->expireInvite($invite);
                    }
                }
            }),
            $delay * 20 // Convert seconds to ticks
        );
    }
    
    private function expireInvite(FactionInvite $invite): void {
        $this->removeInvite($invite->getPlayerName());
        
        // Notify player if online
        $player = Server::getInstance()->getPlayerExact($invite->getPlayerName());
        if ($player instanceof Player) {
            $messageManager = $this->plugin->getMessageManager();
            $player->sendMessage($messageManager->getMessage("faction_invite_expired", [
                "{faction}" => $invite->getFactionName()
            ]));
        }
    }
    
    private function startCleanupTask(): void {
        // Clean expired invites every 5 minutes
        $this->plugin->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void {
                $this->cleanupExpiredInvites();
            }),
            20 * 60 * 5 // 5 minutes in ticks
        );
    }
    
    private function cleanupExpiredInvites(): void {
        $expiredInvites = [];
        
        foreach ($this->invites as $playerName => $invite) {
            if ($this->isInviteExpired($invite)) {
                $expiredInvites[] = $playerName;
            }
        }
        
        foreach ($expiredInvites as $playerName) {
            $this->removeInvite($playerName);
        }
        
        if (!empty($expiredInvites)) {
            $this->plugin->getLogger()->debug("Cleaned up " . count($expiredInvites) . " expired invites");
        }
    }
    
    private function formatTimeRemaining(int $seconds): string {
        if ($seconds <= 0) {
            return "expired";
        }
        
        if ($seconds < 60) {
            return $seconds . " second" . ($seconds !== 1 ? "s" : "");
        }
        
        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . " minute" . ($minutes !== 1 ? "s" : "");
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        $result = $hours . " hour" . ($hours !== 1 ? "s" : "");
        if ($remainingMinutes > 0) {
            $result .= " and " . $remainingMinutes . " minute" . ($remainingMinutes !== 1 ? "s" : "");
        }
        
        return $result;
    }
    
    public function getInviteCount(): int {
        return count($this->invites);
    }
    
    public function getActiveInviteCount(): int {
        $count = 0;
        foreach ($this->invites as $invite) {
            if (!$this->isInviteExpired($invite)) {
                $count++;
            }
        }
        return $count;
    }
    
    public function getFactionInviteCount(Faction $faction): int {
        $count = 0;
        foreach ($this->invites as $invite) {
            if ($invite->getFactionName() === $faction->getName() && !$this->isInviteExpired($invite)) {
                $count++;
            }
        }
        return $count;
    }
    
    public function getInvitesByInviter(string $inviterName): array {
        $invites = [];
        foreach ($this->invites as $invite) {
            if ($invite->getInviter() === $inviterName && !$this->isInviteExpired($invite)) {
                $invites[] = $invite;
            }
        }
        return $invites;
    }
    
    public function isLoaded(): bool {
        return $this->loaded;
    }
    
    public function saveAll(): void {
        // InviteManager doesn't need to save all as invites are saved individually
        // This method is here for consistency with other managers
    }
}