-- #!sqlite

-- #{ table
-- #{ factions
CREATE TABLE IF NOT EXISTS factions
(
    factionName VARCHAR(32) PRIMARY KEY NOT NULL,
    displayName VARCHAR(32) NOT NULL,
    description TEXT DEFAULT NULL,
    creationTime INTEGER DEFAULT 0,
    lastActive INTEGER DEFAULT 0,
    players TEXT DEFAULT NULL,
    home TEXT DEFAULT NULL,
    power INTEGER DEFAULT 2,
    maxPower INTEGER DEFAULT 100,
    kills INTEGER DEFAULT 0,
    deaths INTEGER DEFAULT 0,
    money INTEGER DEFAULT 0,
    freeze BOOLEAN NOT NULL DEFAULT false,
    freezeTime INTEGER DEFAULT 0,
    relations TEXT DEFAULT NULL,
    settings TEXT DEFAULT NULL,
    bannerData TEXT DEFAULT NULL,
    totalClaims INTEGER DEFAULT 0,
    totalAllies INTEGER DEFAULT 0,
    totalMembers INTEGER DEFAULT 1
);
-- #}

-- #{ players
CREATE TABLE IF NOT EXISTS players
(
    playerName VARCHAR(16) PRIMARY KEY NOT NULL,
    playerUUID VARCHAR(36) UNIQUE NOT NULL,
    faction VARCHAR(32) DEFAULT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'member',
    joinTime INTEGER DEFAULT 0,
    lastSeen INTEGER DEFAULT 0,
    kills INTEGER DEFAULT 0,
    deaths INTEGER DEFAULT 0,
    powerContribution INTEGER DEFAULT 0,
    chatMode VARCHAR(16) DEFAULT 'public',
    settings TEXT DEFAULT NULL,
    FOREIGN KEY (faction) REFERENCES factions(factionName) ON DELETE SET NULL ON UPDATE CASCADE
);
-- #}

-- #{ claims
CREATE TABLE IF NOT EXISTS claims
(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chunkX INTEGER NOT NULL,
    chunkZ INTEGER NOT NULL,
    world VARCHAR(64) NOT NULL,
    factionName VARCHAR(32) NOT NULL,
    claimTime INTEGER DEFAULT 0,
    claimedBy VARCHAR(16) DEFAULT NULL,
    UNIQUE (chunkX, chunkZ, world),
    FOREIGN KEY (factionName) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- #{ faction_invites
CREATE TABLE IF NOT EXISTS faction_invites
(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    factionName VARCHAR(32) NOT NULL,
    playerName VARCHAR(16) NOT NULL,
    invitedBy VARCHAR(16) NOT NULL,
    inviteTime INTEGER NOT NULL,
    expiresAt INTEGER NOT NULL,
    UNIQUE (factionName, playerName),
    FOREIGN KEY (factionName) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- #{ ally_requests
CREATE TABLE IF NOT EXISTS ally_requests
(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fromFaction VARCHAR(32) NOT NULL,
    toFaction VARCHAR(32) NOT NULL,
    requestedBy VARCHAR(16) NOT NULL,
    requestTime INTEGER NOT NULL,
    expiresAt INTEGER NOT NULL,
    UNIQUE (fromFaction, toFaction),
    FOREIGN KEY (fromFaction) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (toFaction) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- #{ faction_logs
CREATE TABLE IF NOT EXISTS faction_logs
(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    factionName VARCHAR(32) NOT NULL,
    action VARCHAR(32) NOT NULL,
    playerName VARCHAR(16) DEFAULT NULL,
    target VARCHAR(32) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    timestamp INTEGER NOT NULL,
    FOREIGN KEY (factionName) REFERENCES factions(factionName) ON DELETE CASCADE ON UPDATE CASCADE
);
-- #}

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_factions_power ON factions(power);
CREATE INDEX IF NOT EXISTS idx_factions_creation ON factions(creationTime);
CREATE INDEX IF NOT EXISTS idx_factions_active ON factions(lastActive);
CREATE INDEX IF NOT EXISTS idx_players_faction ON players(faction);
CREATE INDEX IF NOT EXISTS idx_players_uuid ON players(playerUUID);
CREATE INDEX IF NOT EXISTS idx_players_role ON players(role);
CREATE INDEX IF NOT EXISTS idx_claims_faction ON claims(factionName);
CREATE INDEX IF NOT EXISTS idx_claims_world ON claims(world);
CREATE INDEX IF NOT EXISTS idx_claims_chunk ON claims(chunkX, chunkZ);
CREATE INDEX IF NOT EXISTS idx_invites_player ON faction_invites(playerName);
CREATE INDEX IF NOT EXISTS idx_invites_expires ON faction_invites(expiresAt);
CREATE INDEX IF NOT EXISTS idx_ally_from ON ally_requests(fromFaction);
CREATE INDEX IF NOT EXISTS idx_ally_to ON ally_requests(toFaction);
CREATE INDEX IF NOT EXISTS idx_ally_expires ON ally_requests(expiresAt);
CREATE INDEX IF NOT EXISTS idx_logs_faction ON faction_logs(factionName);
CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON faction_logs(timestamp);
CREATE INDEX IF NOT EXISTS idx_logs_action ON faction_logs(action);
-- #}

-- #{ data
-- #{ createPlayerData
-- #  :playerName string
-- #  :playerUUID string
-- #  :faction ?string
-- #  :role string
-- #  :joinTime int
-- #  :lastSeen int
INSERT OR REPLACE INTO players(
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
);
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
INSERT OR IGNORE INTO factions(
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
INSERT OR REPLACE INTO faction_invites(
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
);
-- #}

-- #{ removeInvite
-- #  :factionName string
-- #  :playerName string
DELETE FROM faction_invites
WHERE factionName = :factionName AND playerName = :playerName;
-- #}

-- #{ getPlayerInvites
-- #  :playerName string
SELECT * FROM faction_invites WHERE playerName = :playerName AND expiresAt > strftime('%s', 'now');
-- #}

-- #{ cleanExpiredInvites
DELETE FROM faction_invites WHERE expiresAt <= strftime('%s', 'now');
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