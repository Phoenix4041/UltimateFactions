<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\claims;

use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;
use Phoenix\ultimatefactions\faction\Faction;
use Phoenix\ultimatefactions\managers\FactionManager;
use Phoenix\ultimatefactions\Main;

class Claim {
    
    private string $factionName;
    private int $chunkX;
    private int $chunkZ;
    private string $world;
    private int $claimedAt;
    
    public function __construct(string $factionName, int $chunkX, int $chunkZ, string $world, int $claimedAt = 0) {
        $this->factionName = $factionName;
        $this->chunkX = $chunkX;
        $this->chunkZ = $chunkZ;
        $this->world = $world;
        $this->claimedAt = $claimedAt > 0 ? $claimedAt : time();
    }
    
    /**
     * Get the faction that owns this claim
     */
    public function getFaction(): ?Faction {
        return Main::getInstance()->getFactionManager()->getFactionByName($this->factionName);
    }
    
    /**
     * Get the faction name
     */
    public function getFactionName(): string {
        return $this->factionName;
    }
    
    /**
     * Get the world object
     */
    public function getWorld(): ?World {
        return Server::getInstance()->getWorldManager()->getWorldByName($this->world);
    }
    
    /**
     * Get the world name
     */
    public function getWorldName(): string {
        return $this->world;
    }
    
    /**
     * Get chunk X coordinate
     */
    public function getChunkX(): int {
        return $this->chunkX;
    }
    
    /**
     * Get chunk Z coordinate
     */
    public function getChunkZ(): int {
        return $this->chunkZ;
    }
    
    /**
     * Get the timestamp when this claim was created
     */
    public function getClaimedAt(): int {
        return $this->claimedAt;
    }
    
    /**
     * Get the center position of this chunk
     */
    public function getChunkCenter(): ?Position {
        $world = $this->getWorld();
        if ($world === null) {
            return null;
        }
        
        $centerX = $this->chunkX * 16 + 8;
        $centerZ = $this->chunkZ * 16 + 8;
        $centerY = $world->getHighestBlockAt($centerX, $centerZ);
        
        return new Position($centerX, $centerY, $centerZ, $world);
    }
    
    /**
     * Get all corner positions of this chunk
     */
    public function getChunkCorners(): array {
        $world = $this->getWorld();
        if ($world === null) {
            return [];
        }
        
        $minX = $this->chunkX * 16;
        $maxX = $minX + 15;
        $minZ = $this->chunkZ * 16;
        $maxZ = $minZ + 15;
        
        return [
            'min' => new Position($minX, 0, $minZ, $world),
            'max' => new Position($maxX, 255, $maxZ, $world)
        ];
    }
    
    /**
     * Check if a position is within this chunk
     */
    public function isPositionInChunk(Position $position): bool {
        if ($position->getWorld()->getFolderName() !== $this->world) {
            return false;
        }
        
        $minX = $this->chunkX * 16;
        $maxX = $minX + 15;
        $minZ = $this->chunkZ * 16;
        $maxZ = $minZ + 15;
        
        $x = $position->getFloorX();
        $z = $position->getFloorZ();
        
        return $x >= $minX && $x <= $maxX && $z >= $minZ && $z <= $maxZ;
    }
    
    /**
     * Get the unique identifier for this claim
     */
    public function getId(): string {
        return $this->chunkX . ":" . $this->chunkZ . ":" . $this->world;
    }
    
    /**
     * Convert claim to array for database storage
     */
    public function toArray(): array {
        return [
            'factionName' => $this->factionName,
            'chunkX' => $this->chunkX,
            'chunkZ' => $this->chunkZ,
            'world' => $this->world,
            'claimedAt' => $this->claimedAt
        ];
    }
    
    /**
     * Create claim from database array
     */
    public static function fromArray(array $data): self {
        return new self(
            $data['factionName'],
            (int) $data['chunkX'],
            (int) $data['chunkZ'],
            $data['world'],
            (int) ($data['claimedAt'] ?? time())
        );
    }
    
    /**
     * Check if this claim is adjacent to another claim
     */
    public function isAdjacentTo(Claim $other): bool {
        if ($this->world !== $other->world) {
            return false;
        }
        
        $xDiff = abs($this->chunkX - $other->chunkX);
        $zDiff = abs($this->chunkZ - $other->chunkZ);
        
        return ($xDiff === 1 && $zDiff === 0) || ($xDiff === 0 && $zDiff === 1);
    }
    
    /**
     * Get distance to another claim
     */
    public function getDistanceTo(Claim $other): float {
        if ($this->world !== $other->world) {
            return PHP_FLOAT_MAX;
        }
        
        $xDiff = $this->chunkX - $other->chunkX;
        $zDiff = $this->chunkZ - $other->chunkZ;
        
        return sqrt($xDiff * $xDiff + $zDiff * $zDiff);
    }
}