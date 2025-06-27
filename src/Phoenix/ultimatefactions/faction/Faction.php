<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\faction;

use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\utils\Utils;

class Faction {
    
    public const ROLE_MEMBER = "member";
    public const ROLE_OFFICER = "officer";
    public const ROLE_LEADER = "leader";
    
    public const MSG_MESSAGE = 0;
    public const MSG_TIP = 1;
    public const MSG_POPUP = 2;
    public const MSG_TITLE = 3;
    
    private array $members = [];
    private array $officers = [];
    private string $leader = "";
    private array $allies = [];
    private ?array $home = null;
    private string $description = "";
    private bool $open = false;
    private array $logs = [];
    
    public function __construct(
        private string $name,
        private int $creationTime,
        private int $power = 0,
        private int $kills = 0,
        private int $deaths = 0,
        private bool $freeze = false,
        private int $freezeTime = 0
    ) {
        // Initialize default power from config
        if ($this->power === 0) {
            $this->power = Main::getInstance()->getConfigManager()->getDefaultPower();
        }
    }
    
    public function getName(): string {
        return $this->name;
    }
    
    public function getCreationTime(): int {
        return $this->creationTime;
    }
    
    public function getFormattedCreationTime(): string {
        return date("Y-m-d H:i:s", $this->creationTime);
    }
    
    public function getPrefix(): string {
        return TextFormat::DARK_GRAY . "[" . TextFormat::AQUA . $this->name . TextFormat::DARK_GRAY . "]" . TextFormat::RESET . " ";
    }
    
    // Member management
    public function addMember(string $playerName, string $role = self::ROLE_MEMBER): void {
        $this->removeMemberFromAllRoles($playerName);
        
        switch ($role) {
            case self::ROLE_LEADER:
                $this->leader = $playerName;
                break;
            case self::ROLE_OFFICER:
                $this->officers[] = $playerName;
                break;
            case self::ROLE_MEMBER:
                $this->members[] = $playerName;
                break;
        }
        
        $this->addLog("Member {$playerName} joined as {$role}");
        $this->update();
    }
    
    public function removeMember(string $playerName): void {
        $this->removeMemberFromAllRoles($playerName);
        $this->addLog("Member {$playerName} left the faction");
        $this->update();
    }
    
    private function removeMemberFromAllRoles(string $playerName): void {
        if ($this->leader === $playerName) {
            $this->leader = "";
        }
        
        $this->officers = array_diff($this->officers, [$playerName]);
        $this->members = array_diff($this->members, [$playerName]);
    }
    
    public function isInFaction(string $playerName): bool {
        return $this->leader === $playerName || 
               in_array($playerName, $this->officers) || 
               in_array($playerName, $this->members);
    }
    
    public function getPlayerRole(string $playerName): ?string {
        if ($this->leader === $playerName) {
            return self::ROLE_LEADER;
        }
        if (in_array($playerName, $this->officers)) {
            return self::ROLE_OFFICER;
        }
        if (in_array($playerName, $this->members)) {
            return self::ROLE_MEMBER;
        }
        return null;
    }
    
    public function getAllMembers(): array {
        $allMembers = $this->members;
        $allMembers = array_merge($allMembers, $this->officers);
        if (!empty($this->leader)) {
            $allMembers[] = $this->leader;
        }
        return array_unique($allMembers);
    }
    
    public function getMembers(): array {
        return $this->members;
    }
    
    public function getOfficers(): array {
        return $this->officers;
    }
    
    public function getLeader(): string {
        return $this->leader;
    }
    
    public function getMemberCount(): int {
        return count($this->getAllMembers());
    }
    
    public function getMaxMembers(): int {
        $config = Main::getInstance()->getConfigManager();
        return $config->getMaxMembers();
    }
    
    public function canAddMember(): bool {
        return $this->getMemberCount() < $this->getMaxMembers();
    }
    
    // Power system
    public function getPower(): int {
        return $this->power;
    }
    
    public function setPower(int $power): void {
        if ($this->isFreeze()) {
            return;
        }
        
        $maxPower = $this->getMaxPower();
        
        if ($power <= 0) {
            $this->power = 0;
            $this->triggerRaidMode();
        } elseif ($power >= $maxPower) {
            $this->power = $maxPower;
        } else {
            $this->power = $power;
        }
        
        $this->update();
    }
    
    public function addPower(int $power): void {
        $this->setPower($this->power + $power);
    }
    
    public function removePower(int $power): void {
        $newPower = $this->power - $power;
        
        if ($newPower <= 0 && !$this->isFreeze()) {
            $this->triggerRaidMode();
        }
        
        $this->setPower($newPower);
    }
    
    public function getMaxPower(): int {
        $config = Main::getInstance()->getConfigManager();
        return $this->getMemberCount() * $config->getPowerPerPlayer();
    }
    
    public function getPowerWithStatus(): string {
        $power = $this->power;
        
        if ($power <= 0) {
            return TextFormat::RED . $power . TextFormat::DARK_GRAY . " (" . TextFormat::RED . "Raidable" . TextFormat::DARK_GRAY . ")";
        }
        
        return TextFormat::GREEN . $power . TextFormat::DARK_GRAY . "/" . TextFormat::GRAY . $this->getMaxPower();
    }
    
    public function isRaidable(): bool {
        return $this->power <= 0;
    }
    
    // Freeze system
    public function setFreeze(bool $freeze): void {
        $this->freeze = $freeze;
        if (!$freeze) {
            $this->freezeTime = 0;
        }
        $this->update();
    }
    
    public function isFreeze(): bool {
        return $this->freeze;
    }
    
    public function setFreezeTime(int $time): void {
        $this->freezeTime = $time;
        $this->update();
    }
    
    public function getFreezeTime(): int {
        return $this->freezeTime;
    }
    
    public function getRemainingFreezeTime(): int {
        return max(0, $this->freezeTime - time());
    }
    
    public function getFreezeStatus(): string {
        if ($this->freeze) {
            $remaining = $this->getRemainingFreezeTime();
            return TextFormat::RED . "Yes " . TextFormat::GRAY . gmdate("i:s", $remaining);
        }
        return TextFormat::GREEN . "No";
    }
    
    private function triggerRaidMode(): void {
        if (!$this->isFreeze()) {
            $this->freeze = true;
            $config = Main::getInstance()->getConfigManager();
            $this->freezeTime = time() + $config->getFreezeTime();
            
            $message = Main::getInstance()->getMessageManager()->getMessage("faction_raid_protection");
            $this->broadcastMessage($message);
            
            $this->addLog("Faction entered raid protection mode");
        }
        $this->power = 0;
    }
    
    // Statistics
    public function getKills(): int {
        return $this->kills;
    }
    
    public function addKill(): void {
        $this->kills++;
        $this->update();
    }
    
    public function getDeaths(): int {
        return $this->deaths;
    }
    
    public function addDeath(): void {
        $this->deaths++;
        $this->update();
    }
    
    public function getKDRatio(): float {
        return $this->deaths > 0 ? round($this->kills / $this->deaths, 2) : (float)$this->kills;
    }
    
    // Home system
    public function getHome(): ?Position {
        if ($this->home === null) {
            return null;
        }
        
        return Utils::arrayToPosition($this->home);
    }
    
    public function setHome(Position $position): void {
        $this->home = Utils::positionToArray($position);
        $this->addLog("Home location updated");
        $this->update();
    }
    
    public function hasHome(): bool {
        return $this->home !== null;
    }
    
    // Description
    public function getDescription(): string {
        return $this->description;
    }
    
    public function setDescription(string $description): void {
        $maxLength = Main::getInstance()->getConfigManager()->getMaxDescriptionLength();
        
        if (strlen($description) > $maxLength) {
            $description = substr($description, 0, $maxLength);
        }
        
        $this->description = $description;
        $this->addLog("Description updated");
        $this->update();
    }
    
    // Open faction
    public function isOpen(): bool {
        return $this->open;
    }
    
    public function setOpen(bool $open): void {
        $this->open = $open;
        $this->addLog($open ? "Faction opened to public" : "Faction closed to public");
        $this->update();
    }
    
    // Alliance system
    public function getAllies(): array {
        return $this->allies;
    }
    
    public function isAlly(string $factionName): bool {
        return in_array($factionName, $this->allies);
    }
    
    public function addAlly(string $factionName): void {
        if (!$this->isAlly($factionName)) {
            $this->allies[] = $factionName;
            $this->addLog("Allied with faction {$factionName}");
            $this->update();
        }
    }
    
    public function removeAlly(string $factionName): void {
        $this->allies = array_diff($this->allies, [$factionName]);
        $this->addLog("Broke alliance with faction {$factionName}");
        $this->update();
    }
    
    public function getAllyCount(): int {
        return count($this->allies);
    }
    
    public function getMaxAllies(): int {
        return Main::getInstance()->getConfigManager()->getMaxAllies();
    }
    
    public function canAddAlly(): bool {
        return $this->getAllyCount() < $this->getMaxAllies();
    }
    
    // Online members
    public function getOnlineMembers(): array {
        $onlineMembers = [];
        
        foreach ($this->getAllMembers() as $memberName) {
            $player = Server::getInstance()->getPlayerExact($memberName);
            if ($player instanceof Player && $player->isOnline()) {
                $onlineMembers[] = $player;
            }
        }
        
        return $onlineMembers;
    }
    
    public function getOnlineMemberCount(): int {
        return count($this->getOnlineMembers());
    }
    
    // Broadcasting
    public function broadcastMessage(string $message, int $type = self::MSG_MESSAGE, string $subMessage = ""): void {
        foreach ($this->getOnlineMembers() as $player) {
            switch ($type) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->sendTitle($message, $subMessage);
                    break;
            }
        }
    }
    
    public function broadcastMessageWithAllies(string $message, int $type = self::MSG_MESSAGE, string $subMessage = ""): void {
        $this->broadcastMessage($message, $type, $subMessage);
        
        $factionManager = Main::getInstance()->getFactionManager();
        foreach ($this->allies as $allyName) {
            $ally = $factionManager->getFactionByName($allyName);
            if ($ally instanceof Faction) {
                $ally->broadcastMessage($message, $type, $subMessage);
            }
        }
    }
    
    // Permissions
    public function canPlayerManage(string $playerName): bool {
        $role = $this->getPlayerRole($playerName);
        return $role === self::ROLE_LEADER || $role === self::ROLE_OFFICER;
    }
    
    public function canPlayerPromote(string $playerName): bool {
        return $this->getPlayerRole($playerName) === self::ROLE_LEADER;
    }
    
    public function canPlayerKick(string $playerName, string $targetName): bool {
        $playerRole = $this->getPlayerRole($playerName);
        $targetRole = $this->getPlayerRole($targetName);
        
        if ($playerRole === self::ROLE_LEADER) {
            return $targetRole !== self::ROLE_LEADER;
        }
        
        if ($playerRole === self::ROLE_OFFICER) {
            return $targetRole === self::ROLE_MEMBER;
        }
        
        return false;
    }
    
    // Logging
    public function addLog(string $message): void {
        $this->logs[] = [
            'timestamp' => time(),
            'message' => $message
        ];
        
        // Keep only last 50 logs
        if (count($this->logs) > 50) {
            $this->logs = array_slice($this->logs, -50);
        }
    }
    
    public function getLogs(): array {
        return $this->logs;
    }
    
    public function getRecentLogs(int $count = 10): array {
        return array_slice($this->logs, -$count);
    }
    
    // Claims
    public function getClaimCount(): int {
        return Main::getInstance()->getClaimManager()->getFactionClaimCount($this->name);
    }
    
    public function getMaxClaims(): int {
        return Main::getInstance()->getConfigManager()->getMaxClaims();
    }
    
    public function canClaim(): bool {
        return $this->getClaimCount() < $this->getMaxClaims() && $this->power > 0;
    }
    
    // Data persistence
    public function update(): void {
        Main::getInstance()->getFactionManager()->updateFaction($this);
    }
    
    public function toArray(): array {
        return [
            'name' => $this->name,
            'creationTime' => $this->creationTime,
            'power' => $this->power,
            'kills' => $this->kills,
            'deaths' => $this->deaths,
            'freeze' => $this->freeze,
            'freezeTime' => $this->freezeTime,
            'leader' => $this->leader,
            'officers' => json_encode($this->officers),
            'members' => json_encode($this->members),
            'allies' => json_encode($this->allies),
            'home' => $this->home ? json_encode($this->home) : null,
            'description' => $this->description,
            'open' => $this->open,
            'logs' => json_encode($this->logs)
        ];
    }
    
    public static function fromArray(array $data): Faction {
        $faction = new Faction(
            $data['name'],
            $data['creationTime'],
            (int)$data['power'],
            (int)$data['kills'],
            (int)$data['deaths'],
            (bool)$data['freeze'],
            (int)$data['freezeTime']
        );
        
        $faction->leader = $data['leader'] ?? '';
        $faction->officers = json_decode($data['officers'] ?? '[]', true);
        $faction->members = json_decode($data['members'] ?? '[]', true);
        $faction->allies = json_decode($data['allies'] ?? '[]', true);
        $faction->home = $data['home'] ? json_decode($data['home'], true) : null;
        $faction->description = $data['description'] ?? '';
        $faction->open = (bool)($data['open'] ?? false);
        $faction->logs = json_decode($data['logs'] ?? '[]', true);
        
        return $faction;
    }
}