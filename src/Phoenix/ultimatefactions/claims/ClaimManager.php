<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\managers;

use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\claims\Claim;
use Phoenix\ultimatefactions\faction\Faction;
use Exception;

class ClaimManager {
    
    private Main $plugin;
    
    /** @var array<string, Claim> */
    private array $claims = [];
    
    /** @var array<string, bool> */
    private array $loadedWorlds = [];
    
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    /**
     * Initialize the claim manager and load claims from database
     */
    public function init(): void {
        $this->loadClaims();
    }
    
    /**
     * Load all claims from database
     */
    private function loadClaims(): void {
        $this->plugin->getDatabase()->executeSelect("claims.getAll", [], function(array $rows): void {
            $count = 0;
            foreach ($rows as $row) {
                $claim = Claim::fromArray($row);
                $this->claims[$claim->getId()] = $claim;
                $count++;
            }
            
            $this->plugin->getLogger()->info("Loaded {$count} claims from database");
        });
    }
    
    /**
     * Get all claims
     */
    public function getClaims(): array {
        return $this->claims;
    }
    
    /**
     * Get claim by chunk coordinates and world
     */
    public function getClaim(int $chunkX, int $chunkZ, string $world): ?Claim {
        $id = $chunkX . ":" . $chunkZ . ":" . $world;
        return $this->claims[$id] ?? null;
    }
    
    /**
     * Get claim at specific coordinates (alias for getClaim)
     */
    public function getClaimAt(int $chunkX, int $chunkZ, string $world): ?Claim {
        return $this->getClaim($chunkX, $chunkZ, $world);
    }
    
    /**
     * Get claim by position
     */
    public function getClaimByPosition(Position $position): ?Claim {
        $world = $position->getWorld();
        $chunkX = $position->getFloorX() >> Chunk::COORD_BIT_SIZE;
        $chunkZ = $position->getFloorZ() >> Chunk::COORD_BIT_SIZE;
        
        return $this->getClaim($chunkX, $chunkZ, $world->getFolderName());
    }
    
    /**
     * Get all claims owned by a faction
     */
    public function getFactionClaims(Faction $faction): array {
        return array_filter($this->claims, function(Claim $claim) use ($faction): bool {
            return $claim->getFactionName() === $faction->getName();
        });
    }
    
    /**
     * Get claims count for a faction
     */
    public function getFactionClaimCount(Faction $faction): int {
        return count($this->getFactionClaims($faction));
    }
    
    /**
     * Get all claims in a world
     */
    public function getWorldClaims(string $worldName): array {
        return array_filter($this->claims, function(Claim $claim) use ($worldName): bool {
            return $claim->getWorldName() === $worldName;
        });
    }
    
    /**
     * Check if a chunk is claimed
     */
    public function isChunkClaimed(int $chunkX, int $chunkZ, string $world): bool {
        return $this->getClaim($chunkX, $chunkZ, $world) !== null;
    }
    
    /**
     * Check if a position is in a claimed chunk
     */
    public function isPositionClaimed(Position $position): bool {
        return $this->getClaimByPosition($position) !== null;
    }
    
    /**
     * Create a new claim
     */
    public function createClaim(Faction $faction, World $world, int $chunkX, int $chunkZ): bool {
        $worldName = $world->getFolderName();
        
        // Check if chunk is already claimed
        if ($this->isChunkClaimed($chunkX, $chunkZ, $worldName)) {
            return false;
        }
        
        // Check claim limits
        if (!$this->canFactionClaim($faction)) {
            return false;
        }
        
        try {
            $claim = new Claim($faction->getName(), $chunkX, $chunkZ, $worldName);
            $this->claims[$claim->getId()] = $claim;
            
            // Save to database
            $this->plugin->getDatabase()->executeInsert("claims.create", [
                "factionName" => $faction->getName(),
                "chunkX" => $chunkX,
                "chunkZ" => $chunkZ,
                "world" => $worldName,
                "claimedAt" => time()
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->plugin->getLogger()->error("Failed to create claim: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a claim
     */
    public function deleteClaim(Claim $claim): bool {
        try {
            // Remove from memory
            unset($this->claims[$claim->getId()]);
            
            // Remove from database
            $this->plugin->getDatabase()->executeGeneric("claims.delete", [
                "chunkX" => $claim->getChunkX(),
                "chunkZ" => $claim->getChunkZ(),
                "world" => $claim->getWorldName()
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->plugin->getLogger()->error("Failed to delete claim: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete claim by coordinates
     */
    public function deleteClaimAt(int $chunkX, int $chunkZ, string $world): bool {
        $claim = $this->getClaim($chunkX, $chunkZ, $world);
        if ($claim === null) {
            return false;
        }
        
        return $this->deleteClaim($claim);
    }
    
    /**
     * Delete all claims of a faction
     */
    public function deleteFactionClaims(Faction $faction): int {
        $claims = $this->getFactionClaims($faction);
        $deleted = 0;
        
        foreach ($claims as $claim) {
            if ($this->deleteClaim($claim)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Check if faction can claim more chunks
     */
    public function canFactionClaim(Faction $faction): bool {
        $config = $this->plugin->getConfigManager();
        $maxClaims = $config->getMaxClaims();
        
        // Check unlimited permission
        if ($faction->getOwner() !== null) {
            $owner = $this->plugin->getServer()->getPlayerExact($faction->getOwner()->getName());
            if ($owner !== null && $owner->hasPermission("ultimatefactions.claims.unlimited")) {
                return true;
            }
        }
        
        $currentClaims = $this->getFactionClaimCount($faction);
        return $currentClaims < $maxClaims;
    }
    
    /**
     * Get claims around a position within specified radius
     */
    public function getClaimsAround(Position $position, int $radius): array {
        $world = $position->getWorld();
        $centerChunkX = $position->getFloorX() >> Chunk::COORD_BIT_SIZE;
        $centerChunkZ = $position->getFloorZ() >> Chunk::COORD_BIT_SIZE;
        
        $claims = [];
        
        for ($x = $centerChunkX - $radius; $x <= $centerChunkX + $radius; $x++) {
            for ($z = $centerChunkZ - $radius; $z <= $centerChunkZ + $radius; $z++) {
                $claim = $this->getClaim($x, $z, $world->getFolderName());
                if ($claim !== null) {
                    $claims[] = $claim;
                }
            }
        }
        
        return $claims;
    }
    
    /**
     * Get adjacent claims to a specific claim
     */
    public function getAdjacentClaims(Claim $claim): array {
        $adjacent = [];
        $chunkX = $claim->getChunkX();
        $chunkZ = $claim->getChunkZ();
        $world = $claim->getWorldName();
        
        // Check all 4 directions
        $directions = [
            [$chunkX + 1, $chunkZ],     // East
            [$chunkX - 1, $chunkZ],     // West
            [$chunkX, $chunkZ + 1],     // South
            [$chunkX, $chunkZ - 1]      // North
        ];
        
        foreach ($directions as [$x, $z]) {
            $adjacentClaim = $this->getClaim($x, $z, $world);
            if ($adjacentClaim !== null) {
                $adjacent[] = $adjacentClaim;
            }
        }
        
        return $adjacent;
    }
    
    /**
     * Check if a faction has connected claims (no isolated claims)
     */
    public function hasConnectedClaims(Faction $faction): bool {
        $claims = $this->getFactionClaims($faction);
        
        if (count($claims) <= 1) {
            return true;
        }
        
        // Use flood fill algorithm to check connectivity
        $visited = [];
        $toVisit = [array_values($claims)[0]]; // Start with first claim
        $visited[] = $toVisit[0]->getId();
        
        while (!empty($toVisit)) {
            $current = array_shift($toVisit);
            $adjacent = $this->getAdjacentClaims($current);
            
            foreach ($adjacent as $adjacentClaim) {
                if ($adjacentClaim->getFactionName() === $faction->getName() && 
                    !in_array($adjacentClaim->getId(), $visited)) {
                    
                    $visited[] = $adjacentClaim->getId();
                    $toVisit[] = $adjacentClaim;
                }
            }
        }
        
        return count($visited) === count($claims);
    }
    
    /**
     * Get claim statistics
     */
    public function getClaimStats(): array {
        $stats = [
            'total' => count($this->claims),
            'by_world' => [],
            'by_faction' => []
        ];
        
        foreach ($this->claims as $claim) {
            $world = $claim->getWorldName();
            $faction = $claim->getFactionName();
            
            if (!isset($stats['by_world'][$world])) {
                $stats['by_world'][$world] = 0;
            }
            $stats['by_world'][$world]++;
            
            if (!isset($stats['by_faction'][$faction])) {
                $stats['by_faction'][$faction] = 0;
            }
            $stats['by_faction'][$faction]++;
        }
        
        return $stats;
    }
    
    /**
     * Save all claims to database
     */
    public function saveAll(): void {
        try {
            $this->plugin->getDatabase()->waitAll();
            $this->plugin->getLogger()->debug("All claims saved to database");
        } catch (Exception $e) {
            $this->plugin->getLogger()->error("Failed to save claims: " . $e->getMessage());
        }
    }
    
    /**
     * Clear all claims from memory (for reload)
     */
    public function clearCache(): void {
        $this->claims = [];
        $this->loadedWorlds = [];
    }
    
    /**
     * Reload claims from database
     */
    public function reload(): void {
        $this->clearCache();
        $this->loadClaims();
    }
}