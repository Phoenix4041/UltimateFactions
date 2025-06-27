<?php

declare(strict_types=1);

namespace Phoenix\ultimatefactions\commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Phoenix\ultimatefactions\Main;
use Phoenix\ultimatefactions\utils\MessageManager;
use Phoenix\ultimatefactions\faction\Faction;
use Phoenix\ultimatefactions\player\FactionPlayer;
use Phoenix\ultimatefactions\utils\Utils; // Assuming Utils class still exists and is adapted

class FactionCommand extends Command implements PluginOwned
{

    public function __construct()
    {
        parent::__construct(
            "ultimatefactions", // Command name from plugin.yml
            "Main UltimateFactions command", // Description from plugin.yml
            null,
            ["uf", "factions", "f"] // Aliases from plugin.yml
        );

        $this->setPermission('ultimatefactions.command'); // Permission from plugin.yml
    }

    public function getOwningPlugin(): Plugin
    {
        return Main::getInstance();
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void
    {
        /** @var MessageManager $messageManager */
        $messageManager = Main::getInstance()->getMessageManager();

        if (!$sender instanceof Player) {
            $sender->sendMessage($messageManager->getMessage("console_only"));
            return;
        }

        if (!$this->testPermission($sender)) {
            $sender->sendMessage($messageManager->getMessage("no_permission"));
            return;
        }

        $playerManager = Main::getInstance()->getPlayerManager();
        $factionManager = Main::getInstance()->getFactionManager();
        $claimManager = Main::getInstance()->getClaimManager();
        $economyProvider = Main::getInstance()->getEconomyProvider();
        $configManager = Main::getInstance()->getConfigManager();
        $cooldownManager = Main::getInstance()->getCooldownManager();

        /** @var FactionPlayer $factionPlayer */
        $factionPlayer = $playerManager->getPlayer($sender->getName());

        $subCommand = strtolower($args[0] ?? "help");

        switch ($subCommand) {
            case "help":
                $helpMessages = [];

                // Add Power Requirements Info
                $config = $configManager->getConfig();
                $firstAllyPower = $config->getNested("power_requirements.first_ally", 50);
                $additionalAllyIncrement = $config->getNested("power_requirements.additional_ally_increment", 25);
                $claimsPerPower = $config->getNested("power_requirements.claims_per_power", 2);

                $currentPower = Main::getInstance()->getPowerManager()->getPlayerPower($sender->getName());
                $maxPower = Main::getInstance()->getPowerManager()->getPlayerMaxPower($sender->getName());

                $helpMessages[] = $messageManager->getMessage("power_info_header");
                $helpMessages[] = $messageManager->getMessage("power_info_members");
                $helpMessages[] = str_replace("{FIRST_ALLY}", (string)$firstAllyPower, $messageManager->getMessage("power_info_allies"));
                $helpMessages[] = str_replace("{INCREMENT}", (string)$additionalAllyIncrement, $messageManager->getMessage("power_info_ally_increment"));
                $helpMessages[] = str_replace("{CLAIMS_PER_POWER}", (string)$claimsPerPower, $messageManager->getMessage("power_info_claims"));
                $helpMessages[] = str_replace(["{CURRENT}", "{MAX}"], [(string)$currentPower, (string)$maxPower], $messageManager->getMessage("power_info_current"));
                $helpMessages[] = $messageManager->getMessage("power_info_footer");

                // Add General Help Commands
                $helpMessages[] = $messageManager->getMessage("help_header");
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_general"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_create"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_disband"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_invite"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_accept"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_leave"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_kick"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_info"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_sethome"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_delhome"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_home"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_claim"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_unclaim"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_deposit"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_withdraw"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_balance"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_ally"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_unally"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_chat"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_bank"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_border"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_top"));
                $helpMessages[] = str_replace("{COMMAND_LABEL}", $commandLabel, $messageManager->getMessage("help_credits"));

                if ($sender->hasPermission("ultimatefactions.command.admin")) {
                    $helpMessages[] = TextFormat::GREEN . "Use /" . $commandLabel . " admin" . TextFormat::WHITE . " Faction admin system";
                }
                $helpMessages[] = $messageManager->getMessage("help_footer");

                $pageHeight = 8;
                $pageNumber = 1;
                if (isset($args[1]) && is_numeric($args[1]) && (int)$args[1] > 0) {
                    $pageNumber = (int)$args[1];
                }

                $chunkMessages = array_chunk($helpMessages, $pageHeight);
                $maxPageNumber = count($chunkMessages);

                if ($maxPageNumber == 0) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . " No help messages available.");
                    return;
                }

                if ($pageNumber > $maxPageNumber) {
                    $pageNumber = $maxPageNumber;
                }

                $sender->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "Faction Help " . TextFormat::RESET . TextFormat::DARK_GRAY . "(" . TextFormat::GREEN . $pageNumber . TextFormat::DARK_GRAY . "/" . TextFormat::GREEN . $maxPageNumber . TextFormat::DARK_GRAY . ")");

                foreach ($chunkMessages[$pageNumber - 1] as $message) {
                    $sender->sendMessage($message);
                }
                break;
            case "create":
                if (isset($args[1])) {
                    if ($factionPlayer->isInFaction()) {
                        $sender->sendMessage($messageManager->getMessage("player_already_faction_self"));
                        return;
                    }
                    $name = $args[1];
                    if (strlen($name) < $configManager->getConfig()->getNested("faction_settings.min_name_length", 3) || strlen($name) > $configManager->getConfig()->getNested("faction_settings.max_name_length", 16)) {
                        $sender->sendMessage(str_replace(["{MIN}", "{MAX}"], [(string)$configManager->getConfig()->getNested("faction_settings.min_name_length", 3), (string)$configManager->getConfig()->getNested("faction_settings.max_name_length", 16)], $messageManager->getMessage("faction_name_length")));
                        return;
                    }
                    if (!preg_match("/^[a-zA-Z0-9]+$/", $name)) {
                        $sender->sendMessage($messageManager->getMessage("faction_name_invalid_characters"));
                        return;
                    }
                    if ($factionManager->factionExists($name)) {
                        $sender->sendMessage($messageManager->getMessage("faction_already_exists", ["FACTION" => $name]));
                        return;
                    }
                    $cost = $configManager->getConfig()->getNested("faction_costs.create", 0);
                    if ($economyProvider->getMoney($sender) < $cost) {
                        $sender->sendMessage(str_replace(["{AMOUNT}", "{CURRENT}"], [(string)$cost, (string)$economyProvider->getMoney($sender)], $messageManager->getMessage("not_enough_money")));
                        return;
                    }

                    $factionManager->createFaction($name, $sender->getName());
                    $economyProvider->subtractMoney($sender, $cost);
                    $sender->sendMessage(str_replace(["{FACTION}", "{MONEY}"], [$name, (string)$cost], $messageManager->getMessage("faction_created")));
                } else {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("create_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " create <name>"));
                }
                break;
            case "disband":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_owner_only"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                $factionManager->disbandFaction($faction->getName());
                $sender->sendMessage($messageManager->getMessage("faction_disbanded"));
                break;
            case "invite":
                if (!isset($args[1])) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("invite_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " invite <player>"));
                    return;
                }
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $targetName = $args[1];
                $targetPlayer = Server::getInstance()->getPlayerExact($targetName);
                if ($targetPlayer === null) {
                    $sender->sendMessage($messageManager->getMessage("player_not_online", ["PLAYER" => $targetName]));
                    return;
                }
                /** @var FactionPlayer $targetFactionPlayer */
                $targetFactionPlayer = $playerManager->getPlayer($targetName);
                if ($targetFactionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_already_faction", ["PLAYER" => $targetName]));
                    return;
                }
                if ($sender->getName() === $targetName) {
                    $sender->sendMessage($messageManager->getMessage("player_choose_yourself"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                if ($faction->hasInvite($targetName)) {
                    $sender->sendMessage($messageManager->getMessage("invite_already_sent", ["PLAYER" => $targetName]));
                    return;
                }
                if (!$sender->hasPermission("ultimatefactions.members.unlimited") && count($faction->getMembers()) >= $factionManager->getFactionMemberLimit($faction)) {
                    $sender->sendMessage($messageManager->getMessage("faction_member_limit_reached"));
                    return;
                }
                $faction->addInvite($targetName);
                $sender->sendMessage(str_replace("{PLAYER}", $targetName, $messageManager->getMessage("invite_sent")));
                $targetPlayer->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("invite_received")));
                break;
            case "accept":
                if (!isset($args[1])) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("accept_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " accept <faction>"));
                    return;
                }
                if ($factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_already_faction_self"));
                    return;
                }
                $factionName = $args[1];
                $faction = $factionManager->getFaction($factionName);
                if ($faction === null) {
                    $sender->sendMessage($messageManager->getMessage("faction_not_found", ["FACTION" => $factionName]));
                    return;
                }
                if (!$faction->hasInvite($sender->getName())) {
                    $sender->sendMessage($messageManager->getMessage("invite_not_found", ["FACTION" => $factionName]));
                    return;
                }
                if (!$sender->hasPermission("ultimatefactions.members.unlimited") && count($faction->getMembers()) >= $factionManager->getFactionMemberLimit($faction)) {
                    $sender->sendMessage($messageManager->getMessage("faction_member_limit_reached"));
                    return;
                }

                $faction->removeInvite($sender->getName());
                $factionManager->addPlayerToFaction($faction, $sender->getName());
                $sender->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("faction_joined")));
                foreach ($faction->getOnlineMembers() as $member) {
                    if ($member->getName() !== $sender->getName()) {
                        $member->sendMessage(str_replace(["{PLAYER}", "{FACTION}"], [$sender->getName(), $faction->getName()], $messageManager->getMessage("faction_member_joined")));
                    }
                }
                break;
            case "leave":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if ($factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_owner_cannot_leave"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                $factionManager->removePlayerFromFaction($faction, $sender->getName());
                $sender->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("faction_left")));
                foreach ($faction->getOnlineMembers() as $member) {
                    $member->sendMessage(str_replace(["{PLAYER}", "{FACTION}"], [$sender->getName(), $faction->getName()], $messageManager->getMessage("faction_member_left")));
                }
                break;
            case "kick":
                if (!isset($args[1])) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("kick_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " kick <player>"));
                    return;
                }
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $targetName = $args[1];
                $targetPlayer = Server::getInstance()->getPlayerExact($targetName);
                if ($sender->getName() === $targetName) {
                    $sender->sendMessage($messageManager->getMessage("player_choose_yourself"));
                    return;
                }
                /** @var FactionPlayer $targetFactionPlayer */
                $targetFactionPlayer = $playerManager->getPlayer($targetName);
                if (!$targetFactionPlayer->isInFaction() || $targetFactionPlayer->getFaction()->getName() !== $factionPlayer->getFaction()->getName()) {
                    $sender->sendMessage($messageManager->getMessage("player_not_in_your_faction", ["PLAYER" => $targetName]));
                    return;
                }
                if ($targetFactionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_cannot_kick_owner"));
                    return;
                }
                if ($factionPlayer->isLeader() && $targetFactionPlayer->isLeader()) {
                    $sender->sendMessage($messageManager->getMessage("faction_cannot_kick_leader_as_leader"));
                    return;
                }

                $faction = $factionPlayer->getFaction();
                $factionManager->removePlayerFromFaction($faction, $targetName);
                $sender->sendMessage(str_replace("{PLAYER}", $targetName, $messageManager->getMessage("member_kicked")));
                if ($targetPlayer !== null) {
                    $targetPlayer->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("kicked_from_faction")));
                }
                foreach ($faction->getOnlineMembers() as $member) {
                    $member->sendMessage(str_replace(["{PLAYER}", "{FACTION}"], [$targetName, $faction->getName()], $messageManager->getMessage("faction_member_left")));
                }
                break;
            case "info":
                $factionName = $args[1] ?? null;
                /** @var Faction|null $faction */
                $faction = null;

                if ($factionName !== null) {
                    $faction = $factionManager->getFaction($factionName);
                    if ($faction === null) {
                        $sender->sendMessage($messageManager->getMessage("faction_not_found", ["FACTION" => $factionName]));
                        return;
                    }
                } else {
                    if (!$factionPlayer->isInFaction()) {
                        $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                        return;
                    }
                    $faction = $factionPlayer->getFaction();
                }

                // Display Faction Info (using existing methods if available in Faction class or building string)
                $sender->sendMessage($messageManager->getMessage("faction_info_header", ["FACTION" => $faction->getName()]));
                $sender->sendMessage($messageManager->getMessage("faction_info_owner", ["OWNER" => $faction->getOwnerName()]));
                $sender->sendMessage($messageManager->getMessage("faction_info_members", ["MEMBERS" => implode(", ", array_map(function($uuid) use ($playerManager){ return $playerManager->getPlayer($uuid)->getName(); }, $faction->getMembers()))])); // Assuming getMembers returns UUIDs
                $sender->sendMessage($messageManager->getMessage("faction_info_allies", ["ALLIES" => implode(", ", $faction->getAllies())]));
                $sender->sendMessage($messageManager->getMessage("faction_info_power", ["POWER" => (string)Main::getInstance()->getPowerManager()->getFactionPower($faction->getName()), "MAXPOWER" => (string)Main::getInstance()->getPowerManager()->getFactionMaxPower($faction->getName())]));
                $sender->sendMessage($messageManager->getMessage("faction_info_claims", ["CLAIMS" => (string)count($faction->getClaims())]));
                $sender->sendMessage($messageManager->getMessage("faction_info_money", ["MONEY" => (string)$faction->getMoney()]));
                $sender->sendMessage($messageManager->getMessage("faction_info_status", ["STATUS" => $faction->isRaidable() ? TextFormat::RED . "Raidable" : TextFormat::GREEN . "Protected"])); // Example for status
                $sender->sendMessage($messageManager->getMessage("faction_info_footer"));
                break;
            case "sethome":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                if (!$claimManager->isChunkClaimedByFaction($sender->getPosition()->getChunkX(), $sender->getPosition()->getChunkZ(), $faction->getName())) {
                    $sender->sendMessage($messageManager->getMessage("faction_sethome_not_in_claim"));
                    return;
                }
                $faction->setHome(new Position($sender->getPosition()->getX(), $sender->getPosition()->getY(), $sender->getPosition()->getZ(), $sender->getWorld()));
                $sender->sendMessage($messageManager->getMessage("faction_home_set"));
                break;
            case "delhome":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                if (!$faction->hasHome()) {
                    $sender->sendMessage($messageManager->getMessage("faction_no_home_set"));
                    return;
                }
                $faction->deleteHome();
                $sender->sendMessage($messageManager->getMessage("faction_home_deleted"));
                break;
            case "home":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                if (!$faction->hasHome()) {
                    $sender->sendMessage($messageManager->getMessage("faction_no_home_set"));
                    return;
                }
                $cooldownTime = $configManager->getConfig()->getNested("teleport_cooldowns.home", 5);
                if ($cooldownManager->isOnCooldown($sender->getName(), "home")) {
                    $sender->sendMessage(str_replace("{TIME}", (string)$cooldownManager->getRemainingCooldown($sender->getName(), "home"), $messageManager->getMessage("teleport_cooldown")));
                    return;
                }
                $cooldownManager->addCooldown($sender->getName(), "home", $cooldownTime);
                $sender->sendMessage(str_replace("{TIME}", (string)$cooldownTime, $messageManager->getMessage("teleporting_in")));
                Main::getInstance()->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function () use ($sender, $faction, $messageManager): void {
                    if ($sender->isOnline() && $faction->hasHome()) {
                        $sender->teleport($faction->getHome());
                        $sender->sendMessage($messageManager->getMessage("teleport_success_home"));
                    }
                }), $cooldownTime * 20); // 20 ticks per second
                break;
            case "claim":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                $chunkX = $sender->getPosition()->getChunkX();
                $chunkZ = $sender->getPosition()->getChunkZ();
                $worldName = $sender->getPosition()->getWorld()->getDisplayName();

                if ($claimManager->isChunkClaimed($chunkX, $chunkZ, $worldName)) {
                    $ownerFactionName = $claimManager->getChunkOwnerFaction($chunkX, $chunkZ, $worldName);
                    if ($ownerFactionName === $faction->getName()) {
                        $sender->sendMessage($messageManager->getMessage("claim_already_owned_self"));
                    } else {
                        $sender->sendMessage($messageManager->getMessage("claim_already_owned_other", ["FACTION" => $ownerFactionName]));
                    }
                    return;
                }
                if (!$sender->hasPermission("ultimatefactions.claims.unlimited") && count($faction->getClaims()) >= $claimManager->getFactionClaimLimit($faction)) {
                    $sender->sendMessage($messageManager->getMessage("claim_limit_reached"));
                    return;
                }
                if (Main::getInstance()->getPowerManager()->getFactionPower($faction->getName()) < ($configManager->getConfig()->getNested("power_requirements.claims_per_power", 2) * (count($faction->getClaims()) + 1))) {
                     $sender->sendMessage($messageManager->getMessage("not_enough_power_claim"));
                     return;
                }

                $cost = $configManager->getConfig()->getNested("faction_costs.claim", 0);
                if ($economyProvider->getMoney($sender) < $cost) {
                    $sender->sendMessage(str_replace(["{AMOUNT}", "{CURRENT}"], [(string)$cost, (string)$economyProvider->getMoney($sender)], $messageManager->getMessage("not_enough_money")));
                    return;
                }

                $claimManager->claimChunk($faction->getName(), $chunkX, $chunkZ, $worldName);
                $economyProvider->subtractMoney($sender, $cost);
                $sender->sendMessage(str_replace(["{X}", "{Z}", "{WORLD}", "{MONEY}"], [(string)$chunkX, (string)$chunkZ, $worldName, (string)$cost], $messageManager->getMessage("chunk_claimed")));
                break;
            case "unclaim":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                $chunkX = $sender->getPosition()->getChunkX();
                $chunkZ = $sender->getPosition()->getChunkZ();
                $worldName = $sender->getPosition()->getWorld()->getDisplayName();

                if (!$claimManager->isChunkClaimedByFaction($chunkX, $chunkZ, $faction->getName())) {
                    $sender->sendMessage($messageManager->getMessage("unclaim_not_owned"));
                    return;
                }

                $claimManager->unclaimChunk($faction->getName(), $chunkX, $chunkZ, $worldName);
                $sender->sendMessage(str_replace(["{X}", "{Z}", "{WORLD}"], [(string)$chunkX, (string)$chunkZ, $worldName], $messageManager->getMessage("chunk_unclaimed")));
                break;
            case "deposit":
                if (!isset($args[1]) || !is_numeric($args[1]) || (float)$args[1] <= 0) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("deposit_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " deposit <amount>"));
                    return;
                }
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                $amount = (float)$args[1];
                if ($economyProvider->getMoney($sender) < $amount) {
                    $sender->sendMessage(str_replace(["{AMOUNT}", "{CURRENT}"], [(string)$amount, (string)$economyProvider->getMoney($sender)], $messageManager->getMessage("not_enough_money")));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                $faction->addMoney($amount);
                $economyProvider->subtractMoney($sender, $amount);
                $sender->sendMessage(str_replace(["{AMOUNT}", "{FACTION}"], [(string)$amount, $faction->getName()], $messageManager->getMessage("money_deposited")));
                foreach ($faction->getOnlineMembers() as $member) {
                    if ($member->getName() !== $sender->getName()) {
                        $member->sendMessage(str_replace(["{PLAYER}", "{AMOUNT}"], [$sender->getName(), (string)$amount], $messageManager->getMessage("faction_member_deposited")));
                    }
                }
                break;
            case "withdraw":
                if (!isset($args[1]) || !is_numeric($args[1]) || (float)$args[1] <= 0) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("withdraw_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " withdraw <amount>"));
                    return;
                }
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $amount = (float)$args[1];
                $faction = $factionPlayer->getFaction();
                if ($faction->getMoney() < $amount) {
                    $sender->sendMessage(str_replace(["{AMOUNT}", "{CURRENT}"], [(string)$amount, (string)$faction->getMoney()], $messageManager->getMessage("faction_not_enough_money")));
                    return;
                }
                $faction->subtractMoney($amount);
                $economyProvider->addMoney($sender, $amount);
                $sender->sendMessage(str_replace(["{AMOUNT}", "{FACTION}"], [(string)$amount, $faction->getName()], $messageManager->getMessage("money_withdrawn")));
                foreach ($faction->getOnlineMembers() as $member) {
                    if ($member->getName() !== $sender->getName()) {
                        $member->sendMessage(str_replace(["{PLAYER}", "{AMOUNT}"], [$sender->getName(), (string)$amount], $messageManager->getMessage("faction_member_withdrew")));
                    }
                }
                break;
            case "balance":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                $sender->sendMessage(str_replace(["{FACTION}", "{MONEY}"], [$faction->getName(), (string)$faction->getMoney()], $messageManager->getMessage("faction_balance")));
                break;
            case "ally":
                if (!isset($args[1])) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("ally_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " ally <faction>"));
                    return;
                }
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $targetFactionName = $args[1];
                $targetFaction = $factionManager->getFaction($targetFactionName);
                if ($targetFaction === null) {
                    $sender->sendMessage($messageManager->getMessage("faction_not_found", ["FACTION" => $targetFactionName]));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                if ($faction->getName() === $targetFaction->getName()) {
                    $sender->sendMessage($messageManager->getMessage("ally_self_faction"));
                    return;
                }
                if ($faction->isAlliedWith($targetFactionName)) {
                    $sender->sendMessage($messageManager->getMessage("ally_already_allied"));
                    return;
                }
                if (!$sender->hasPermission("ultimatefactions.ally.unlimited") && count($faction->getAllies()) >= $factionManager->getFactionAllyLimit($faction)) {
                    $sender->sendMessage($messageManager->getMessage("ally_limit_reached"));
                    return;
                }
                if ($faction->hasPendingAllyRequest($targetFactionName)) {
                    // This means target faction sent request to us, we can accept
                    $faction->removePendingAllyRequest($targetFactionName);
                    $faction->addAlliedFaction($targetFactionName);
                    $targetFaction->addAlliedFaction($faction->getName());
                    $sender->sendMessage(str_replace("{FACTION}", $targetFactionName, $messageManager->getMessage("ally_request_accepted")));
                    foreach ($targetFaction->getOnlineMembers() as $member) {
                        $member->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("ally_request_accepted_target")));
                    }
                    return;
                }
                if ($targetFaction->hasPendingAllyRequest($faction->getName())) {
                    $sender->sendMessage($messageManager->getMessage("ally_request_already_sent_target"));
                    return;
                }

                $faction->addPendingAllyRequest($targetFactionName);
                $sender->sendMessage(str_replace("{FACTION}", $targetFactionName, $messageManager->getMessage("ally_request_sent")));
                foreach ($targetFaction->getOnlineMembers() as $member) {
                    $member->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("ally_request_received")));
                }
                break;
            case "unally":
                if (!isset($args[1])) {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("unally_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " unally <faction>"));
                    return;
                }
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                if (!$factionPlayer->isLeader() && !$factionPlayer->isOwner()) {
                    $sender->sendMessage($messageManager->getMessage("faction_leader_owner_only"));
                    return;
                }
                $targetFactionName = $args[1];
                $targetFaction = $factionManager->getFaction($targetFactionName);
                if ($targetFaction === null) {
                    $sender->sendMessage($messageManager->getMessage("faction_not_found", ["FACTION" => $targetFactionName]));
                    return;
                }
                $faction = $factionPlayer->getFaction();
                if (!$faction->isAlliedWith($targetFactionName)) {
                    $sender->sendMessage($messageManager->getMessage("unally_not_allied"));
                    return;
                }

                $faction->removeAlliedFaction($targetFactionName);
                $targetFaction->removeAlliedFaction($faction->getName());
                $sender->sendMessage(str_replace("{FACTION}", $targetFactionName, $messageManager->getMessage("unally_success")));
                foreach ($targetFaction->getOnlineMembers() as $member) {
                    $member->sendMessage(str_replace("{FACTION}", $faction->getName(), $messageManager->getMessage("unally_success_target")));
                }
                break;
            case "chat":
                if (!$factionPlayer->isInFaction()) {
                    $sender->sendMessage($messageManager->getMessage("player_no_faction_self"));
                    return;
                }
                $chatMode = strtolower($args[1] ?? "");
                $currentChatMode = $factionPlayer->getChatMode();
                $newChatMode = $currentChatMode;

                switch ($chatMode) {
                    case "f":
                    case "faction":
                        $newChatMode = FactionPlayer::CHAT_MODE_FACTION;
                        break;
                    case "a":
                    case "ally":
                        if (!empty($factionPlayer->getFaction()->getAllies())) {
                            $newChatMode = FactionPlayer::CHAT_MODE_ALLIANCE;
                        } else {
                            $sender->sendMessage($messageManager->getMessage("chat_no_allies"));
                            return;
                        }
                        break;
                    case "p":
                    case "public":
                        $newChatMode = FactionPlayer::CHAT_MODE_PUBLIC;
                        break;
                    default:
                        // Toggle logic if no specific mode is given
                        if ($currentChatMode === FactionPlayer::CHAT_MODE_FACTION) {
                            $newChatMode = FactionPlayer::CHAT_MODE_PUBLIC;
                        } elseif ($currentChatMode === FactionPlayer::CHAT_MODE_ALLIANCE) {
                            $newChatMode = FactionPlayer::CHAT_MODE_PUBLIC;
                        } else { // Current mode is public, try faction
                            $newChatMode = FactionPlayer::CHAT_MODE_FACTION;
                        }

                        // If trying to set ally chat without allies, revert to public/faction
                        if ($newChatMode === FactionPlayer::CHAT_MODE_ALLIANCE && empty($factionPlayer->getFaction()->getAllies())) {
                             $newChatMode = FactionPlayer::CHAT_MODE_FACTION; // Fallback to faction chat
                             if ($newChatMode === FactionPlayer::CHAT_MODE_FACTION && !$factionPlayer->isInFaction()){ // If player has no faction, force public
                                $newChatMode = FactionPlayer::CHAT_MODE_PUBLIC;
                            }
                        }
                        break;
                }
                
                $factionPlayer->setChatMode($newChatMode);
                $sender->sendMessage(str_replace("{MODE}", ucfirst($newChatMode), $messageManager->getMessage("chat_mode_changed")));
                break;
            case "bank":
                $bankSubCommand = strtolower($args[1] ?? "balance"); // Default to balance
                switch ($bankSubCommand) {
                    case "deposit":
                        array_shift($args); // remove "bank"
                        array_shift($args); // remove "deposit"
                        $this->execute($sender, $commandLabel, ["deposit", $args[0] ?? null]);
                        break;
                    case "withdraw":
                        array_shift($args); // remove "bank"
                        array_shift($args); // remove "withdraw"
                        $this->execute($sender, $commandLabel, ["withdraw", $args[0] ?? null]);
                        break;
                    case "balance":
                        array_shift($args); // remove "bank"
                        array_shift($args); // remove "balance"
                        $this->execute($sender, $commandLabel, ["balance"]);
                        break;
                    default:
                        $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("bank_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " bank [deposit/withdraw/balance]"));
                        break;
                }
                break;
            case "border":
                if ($cooldownManager->isOnCooldown($sender->getName(), "chunk_border_toggle")) {
                    $sender->sendMessage(str_replace("{TIME}", (string)$cooldownManager->getRemainingCooldown($sender->getName(), "chunk_border_toggle"), $messageManager->getMessage("command_cooldown")));
                    return;
                }
                
                $cooldownManager->addCooldown($sender->getName(), "chunk_border_toggle", 3); // 3 second cooldown
                
                if (Main::getInstance()->isChunkBorderEnabled($sender->getName())) {
                    Main::getInstance()->disableChunkBorder($sender->getName());
                    $sender->sendMessage($messageManager->getMessage("chunk_border_disabled"));
                } else {
                    Main::getInstance()->enableChunkBorder($sender->getName());
                    $sender->sendMessage($messageManager->getMessage("chunk_border_enabled"));
                }
                break;
            case "top":
                $type = strtolower($args[1] ?? "power"); // Default to power leaderboard
                $length = (int)($args[2] ?? 10); // Default to top 10

                $factions = [];
                if ($type === "power") {
                    $factions = $factionManager->getTopFactionsByPower();
                    $header = $messageManager->getMessage("top_power_header");
                } elseif ($type === "kills") {
                    $factions = $factionManager->getTopFactionsByKills();
                    $header = $messageManager->getMessage("top_kills_header");
                } else {
                    $sender->sendMessage($messageManager->getMessage("prefix") . ($messageManager->getMessage("top_command_usage") ?? TextFormat::RED . "Usage: /" . $commandLabel . " top <optional: kills / power> <optional: length>"));
                    return;
                }

                $sender->sendMessage($header);
                $count = 0;
                foreach ($factions as $factionName => $value) {
                    $count++;
                    if ($count > $length) break;
                    $sender->sendMessage(str_replace(["{RANK}", "{FACTION}", "{VALUE}"], [(string)$count, $factionName, (string)$value], $messageManager->getMessage("top_list_entry")));
                }
                if ($count === 0) {
                    $sender->sendMessage($messageManager->getMessage("no_top_factions_found"));
                }
                $sender->sendMessage($messageManager->getMessage("top_footer"));
                break;
            case "credits":
                $sender->sendMessage($messageManager->getMessage("credits_header"));
                $sender->sendMessage($messageManager->getMessage("credits_line_1"));
                $sender->sendMessage($messageManager->getMessage("credits_line_2"));
                $sender->sendMessage($messageManager->getMessage("credits_footer"));
                break;
            default:
                $sender->sendMessage($messageManager->getMessage("unknown_command", ["command" => "/" . $commandLabel . " help"]));
                break;
        }
    }
}