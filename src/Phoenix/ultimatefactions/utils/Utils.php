<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\utils;

use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Phoenix\ultimatefactions\Main;

class Utils {

    public static function getPrefix(): string {
        return TextFormat::colorize(Main::getInstance()->getConfigManager()->getConfig()->get("server_prefix", "&7[&bUltimateFactions&7]")) . TextFormat::RESET . " ";
    }

    public static function getPositionToArray(array $data): ?Position {
        if (count($data) < 4) return null;
        
        $x = intval($data[0]);
        $y = intval($data[1]);
        $z = intval($data[2]);
        $worldName = $data[3];

        $worldManager = Server::getInstance()->getWorldManager();

        if (!$worldManager->isWorldGenerated($worldName)) return null;

        if (!$worldManager->isWorldLoaded($worldName)) {
            $worldManager->loadWorld($worldName);
        }

        $world = $worldManager->getWorldByName($worldName);
        if ($world === null) return null;

        return new Position($x, $y, $z, $world);
    }

    public static function getArrayToPosition(?Position $position): array {
        if ($position === null) return [];

        return [
            $position->getX(), 
            $position->getY(), 
            $position->getZ(), 
            $position->getWorld()->getFolderName()
        ];
    }

    public static function distance(Vector3 $pos1, Vector3 $pos2): float {
        return sqrt(self::distanceSquared($pos1, $pos2));
    }

    public static function distanceSquared(Vector3 $pos1, Vector3 $pos2): float {
        return (($pos1->x - $pos2->x) ** 2) + (($pos1->z - $pos2->z) ** 2);
    }

    public static function getZoneColor(?string $playerFactionName, ?string $zoneFactionName): string {
        if ($zoneFactionName === null) {
            return TextFormat::YELLOW; // Wilderness
        }

        if ($playerFactionName !== null) {
            if ($playerFactionName === $zoneFactionName) {
                return TextFormat::GREEN; // Own faction
            }
            
            // Check if ally (would need faction manager access)
            $factionManager = Main::getInstance()->getFactionManager();
            $playerFaction = $factionManager->getFaction($playerFactionName);
            
            if ($playerFaction !== null && $playerFaction->isAlly($zoneFactionName)) {
                return TextFormat::BLUE; // Ally faction
            }
        }

        return TextFormat::RED; // Enemy faction
    }

    public static function formatTime(int $seconds): string {
        if ($seconds === 0) {
            return "0 seconds";
        }

        $timeString = "";
        $timeArray = [];

        if ($seconds >= 86400) {
            $unit = intval(floor($seconds / 86400));
            $seconds -= $unit * 86400;
            $timeArray[] = $unit . ($unit === 1 ? " day" : " days");
        }

        if ($seconds >= 3600) {
            $unit = intval(floor($seconds / 3600));
            $seconds -= $unit * 3600;
            $timeArray[] = $unit . ($unit === 1 ? " hour" : " hours");
        }

        if ($seconds >= 60) {
            $unit = intval(floor($seconds / 60));
            $seconds -= $unit * 60;
            $timeArray[] = $unit . ($unit === 1 ? " minute" : " minutes");
        }

        if ($seconds >= 1) {
            $timeArray[] = $seconds . ($seconds === 1 ? " second" : " seconds");
        }

        foreach ($timeArray as $key => $value) {
            if ($key === 0) {
                $timeString .= $value;
            } elseif ($key === count($timeArray) - 1) {
                $timeString .= " and " . $value;
            } else {
                $timeString .= ", " . $value;
            }
        }

        return $timeString;
    }

    public static function getChunkX(float $x): int {
        return intval($x) >> 4;
    }

    public static function getChunkZ(float $z): int {
        return intval($z) >> 4;
    }

    public static function formatMoney(float $amount): string {
        return "$" . number_format($amount, 2);
    }

    public static function colorize(string $text): string {
        return TextFormat::colorize($text);
    }

    public static function stripColors(string $text): string {
        return TextFormat::clean($text);
    }

    public static function isValidFactionName(string $name): bool {
        return preg_match('/^[a-zA-Z0-9_]{3,16}$/', $name) === 1;
    }

    public static function isValidPlayerName(string $name): bool {
        return preg_match('/^[a-zA-Z0-9_]{3,16}$/', $name) === 1;
    }
}