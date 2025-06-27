-- #!mysql

-- #{ table
-- #{ factions
CREATE TABLE IF NOT EXISTS factions
(
    factionName VARCHAR(32) PRIMARY KEY NOT NULL,
    displayName VARCHAR(32) NOT NULL,
    description TEXT DEFAULT NULL,
    creationTime BIGINT DEFAULT 0,
    lastActive BIGINT DEFAULT 0,
    players TEXT DEFAULT NULL,
    home TEXT DEFAULT NULL,
    power INTEGER DEFAULT 2,
    maxPower INTEGER DEFAULT 100,
    kills INTEGER DEFAULT 0,
    deaths INTEGER DEFAULT 0,
    money BIGINT DEFAULT 0,
    freeze BOOLEAN NOT NULL DEFAULT false,
    freezeTime BIGINT DEFAULT 0,
    relations TEXT DEFAULT NULL,
    settings TEXT DEFAULT NULL,
    bannerData TEXT DEFAULT NULL,
    totalClaims INTEGER DEFAULT 0,
    totalAllies INTEGER DEFAULT 0,
    totalMembers INTEGER DEFAULT 1,
    INDEX idx_power (power),
    INDEX idx_creation (creationTime),
    INDEX idx_active (lastActive)
);
-- #}

-- #{ players
CREATE TABLE IF NOT EXISTS players
(
    playerName VARCHAR(16) PRIMARY KEY NOT NULL,
    playerUUID VARCHAR(36) UNIQUE NOT NULL,
    faction VARCHAR(32) DEFAULT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'member',
    joinTime BIGINT DEFAULT 0,
    lastSeen BIGINT DEFAULT 0,
    kills INTEGER DEFAULT 0,
    deaths INTEGER DEFAULT 0,
    powerContribution INTEGER DEFAULT 0,
    chatMode VARCHAR(16) DEFAULT 'public',
    settings TEXT DEFAULT NULL,
    INDEX idx_faction (faction),
    INDEX idx_uuid (playerUUID),
    INDEX idx_role (role),
    FOREIGN KEY (faction) REFERENCES factions(factionName) ON DELETE SET NULL ON UPDATE CASCADE
);
-- #}

-- #{ claims
CREATE TABLE IF NOT EXISTS claims
(
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    chunkX INTEGER NOT NULL,
    chunkZ INTEGER NOT NULL,
    world VARCHAR(64) NOT NULL,
    factionName VARCHAR(32) NOT NULL,
    claimTime BIGINT DEFAULT 0,
    claimedBy VARCHAR(16) DEFAULT NULL,
    UNIQUE KEY unique_chunk (chunkX, chunkZ, world),
    INDEX idx_faction (factionName),
    INDEX idx_world (world),
    INDEX idx_chunk (chunkX, chunkZ),
    FOREIGN KEY (factionName) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- #{ faction_invites
CREATE TABLE IF NOT EXISTS faction_invites
(
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    factionName VARCHAR(32) NOT NULL,
    playerName VARCHAR(16) NOT NULL,
    invitedBy VARCHAR(16) NOT NULL,
    inviteTime BIGINT NOT NULL,
    expiresAt BIGINT NOT NULL,
    UNIQUE KEY unique_invite (factionName, playerName),
    INDEX idx_player (playerName),
    INDEX idx_expires (expiresAt),
    FOREIGN KEY (factionName) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- #{ ally_requests
CREATE TABLE IF NOT EXISTS ally_requests
(
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    fromFaction VARCHAR(32) NOT NULL,
    toFaction VARCHAR(32) NOT NULL,
    requestedBy VARCHAR(16) NOT NULL,
    requestTime BIGINT NOT NULL,
    expiresAt BIGINT NOT NULL,
    UNIQUE KEY unique_request (fromFaction, toFaction),
    INDEX idx_from (fromFaction),
    INDEX idx_to (toFaction),
    INDEX idx_expires (expiresAt),
    FOREIGN KEY (fromFaction) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (toFaction) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- #{ faction_logs
CREATE TABLE IF NOT EXISTS faction_logs
(
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    factionName VARCHAR(32) NOT NULL,
    action VARCHAR(32) NOT NULL,
    playerName VARCHAR(16) DEFAULT NULL,
    target VARCHAR(32) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    timestamp BIGINT NOT NULL,
    INDEX idx_faction (factionName),
    INDEX idx_timestamp (timestamp),
    INDEX idx_action (action),
    FOREIGN KEY (factionName) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}
-- #}

-- #{ data
-- #{ createPlayerData
-- #  :playerName string
-- #  :playerUUID string
-- #  :faction ?string
-- #  :role string
-- #  :joinTime int
-- #  :lastSeen int
INSERT INTO players(
    playerName,
    playerUUID,
    faction,
    role,
    joinTime,
    lastSeen
) VALUES (
    :playerName,
    :playerUUID,
    :faction,
    :role,
    :joinTime,
    :lastSeen
) ON DUPLICATE KEY UPDATE
    lastSeen = :lastSeen;
-- #}

-- #{ selectPlayerData
-- #  :playerName string
SELECT * FROM players WHERE playerName = :playerName;
-- #}

-- #{ selectPlayerByUUID
-- #  :playerUUID string
SELECT * FROM players WHERE playerUUID = :playerUUID;
-- #}

-- #{ updatePlayerData
-- #  :playerName string
-- #  :faction ?string
-- #  :role string
-- #  :lastSeen int
UPDATE players
SET faction = :faction,
    role = :role,
    lastSeen = :lastSeen
WHERE playerName = :playerName;
-- #}

-- #{ selectAllPlayers
SELECT * FROM players;
-- #}

-- #{ createFaction
-- #  :factionName string
-- #  :displayName string
-- #  :description ?string
-- #  :creationTime int
-- #  :lastActive int
-- #  :players ?string
-- #  :home ?string
-- #  :power int
-- #  :maxPower int
-- #  :kills int
-- #  :deaths int
-- #  :money int
-- #  :freeze bool
-- #  :freezeTime int
-- #  :relations ?string
-- #  :settings ?string
-- #  :totalMembers int
INSERT INTO factions(
    factionName,
    displayName,
    description,
    creationTime,
    lastActive,
    players,
    home,
    power,
    maxPower,
    kills,
    deaths,
    money,
    freeze,
    freezeTime,
    relations,
    settings,
    totalMembers
) VALUES (
    :factionName,
    :displayName,
    :description,
    :creationTime,
    :lastActive,
    :players,
    :home,
    :power,
    :maxPower,
    :kills,
    :deaths,
    :money,
    :freeze,
    :freezeTime,
    :relations,
    :settings,
    :totalMembers
);
-- #}

-- #{ deleteFaction
-- #  :factionName string
DELETE FROM factions WHERE factionName = :factionName;
-- #}

-- #{ updateFaction
-- #  :factionName string
-- #  :displayName string
-- #  :description ?string
-- #  :lastActive int
-- #  :players ?string
-- #  :home ?string
-- #  :power int
-- #  :maxPower int
-- #  :kills int
-- #  :deaths int
-- #  :money int
-- #  :freeze bool
-- #  :freezeTime int
-- #  :relations ?string
-- #  :settings ?string
-- #  :totalClaims int
-- #  :totalAllies int
-- #  :totalMembers int
UPDATE factions
SET displayName = :displayName,
    description = :description,
    lastActive = :lastActive,
    players = :players,
    home = :home,
    power = :power,
    maxPower = :maxPower,
    kills = :kills,
    deaths = :deaths,
    money = :money,
    freeze = :freeze,
    freezeTime = :freezeTime,
    relations = :relations,
    settings = :settings,
    totalClaims = :totalClaims,
    totalAllies = :totalAllies,
    totalMembers = :totalMembers
WHERE factionName = :factionName;
-- #}

-- #{ selectAllFactions
SELECT * FROM factions;
-- #}

-- #{ selectFaction
-- #  :factionName string
SELECT * FROM factions WHERE factionName = :factionName;
-- #}

-- #{ createClaim
-- #  :factionName string
-- #  :chunkX int
-- #  :chunkZ int
-- #  :world string
-- #  :claimTime int
-- #  :claimedBy string
INSERT INTO claims(
    factionName,
    chunkX,
    chunkZ,
    world,
    claimTime,
    claimedBy
) VALUES (
    :factionName,
    :chunkX,
    :chunkZ,
    :world,
    :claimTime,
    :claimedBy
);
-- #}

-- #{ deleteClaim
-- #  :chunkX int
-- #  :chunkZ int
-- #  :world string
DELETE FROM claims
WHERE chunkX = :chunkX
  AND chunkZ = :chunkZ
  AND world = :world;
-- #}

-- #{ selectAllClaims
SELECT * FROM claims;
-- #}

-- #{ selectClaimsByFaction
-- #  :factionName string
SELECT * FROM claims WHERE factionName = :factionName;
-- #}

-- #{ selectClaimAt
-- #  :chunkX int
-- #  :chunkZ int
-- #  :world string
SELECT * FROM claims
WHERE chunkX = :chunkX
  AND chunkZ = :chunkZ
  AND world = :world;
-- #}

-- #{ addFactionInvite
-- #  :factionName string
-- #  :playerName string
-- #  :invitedBy string
-- #  :inviteTime int
-- #  :expiresAt int
INSERT INTO faction_invites(
    factionName,
    playerName,
    invitedBy,
    inviteTime,
    expiresAt
) VALUES (
    :factionName,
    :playerName,
    :invitedBy,
    :inviteTime,
    :expiresAt
) ON DUPLICATE KEY UPDATE
    invitedBy = :invitedBy,
    inviteTime = :inviteTime,
    expiresAt = :expiresAt;
-- #}

-- #{ removeInvite
-- #  :factionName string
-- #  :playerName string
DELETE FROM faction_invites
WHERE factionName = :factionName AND playerName = :playerName;
-- #}

-- #{ getPlayerInvites
-- #  :playerName string
SELECT * FROM faction_invites WHERE playerName = :playerName AND expiresAt > UNIX_TIMESTAMP();
-- #}

-- #{ cleanExpiredInvites
DELETE FROM faction_invites WHERE expiresAt <= UNIX_TIMESTAMP();
-- #}

-- #{ logFactionAction
-- #  :factionName string
-- #  :action string
-- #  :playerName ?string
-- #  :target ?string
-- #  :details ?string
-- #  :timestamp int
INSERT INTO faction_logs(
    factionName,
    action,
    playerName,
    target,
    details,
    timestamp
) VALUES (
    :factionName,
    :action,
    :playerName,
    :target,
    :details,
    :timestamp
);
-- #}

-- #{ getFactionLogs
-- #  :factionName string
-- #  :limit int
SELECT * FROM faction_logs
WHERE factionName = :factionName
ORDER BY timestamp DESC
LIMIT :limit;
-- #}

-- #{ cleanOldLogs
-- #  :beforeTimestamp int
DELETE FROM faction_logs WHERE timestamp < :beforeTimestamp;
-- #}
-- #}