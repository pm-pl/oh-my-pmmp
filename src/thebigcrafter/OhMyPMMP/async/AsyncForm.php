<?php

/*
 * This file is part of oh-my-pmmp.
 * (c) thebigcrafter <hello.thebigcrafter@gmail.com>
 * This source file is subject to the GPL-3.0 license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace thebigcrafter\OhMyPMMP\async;

use dktapps\pmforms\CustomForm;
use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\CustomFormElement;
use dktapps\pmforms\element\Label;
use dktapps\pmforms\MenuForm;
use dktapps\pmforms\MenuOption;
use dktapps\pmforms\ModalForm;
use Generator;
use pocketmine\player\Player;
use pocketmine\Server;
use SOFe\AwaitGenerator\Await;
use thebigcrafter\OhMyPMMP\cache\PluginCache;
use thebigcrafter\OhMyPMMP\cache\PluginsPool;
use thebigcrafter\OhMyPMMP\utils\Utils;
use function array_keys;
use function array_map;
use function implode;
use function is_null;
use function version_compare;

class AsyncForm {

	private const ACTION_SHOW = 0;
	private const ACTION_INSTALL = 1;

	public static function groupsForm(Player $player) : Generator {
		$listGroups = array_keys(Utils::groupByFirstLetter());

		$formResult = yield from self::menu($player, "Plugins - List", "Choose group first", array_map(function(string $group){
			return new MenuOption($group);
		}, $listGroups));
		if($formResult !== null) {
			yield self::pluginsForm($player, (string) $listGroups[$formResult]);
		}
	}

	public static function pluginsForm(Player $player, string $group) : Generator {
		$listPlugins = Utils::groupByFirstLetter()[$group];
		$options = [];
		foreach($listPlugins as $plugin) {
			/** @phpstan-var string $plugin */
			$options[] = new MenuOption($plugin);
		}
		$formResult = yield from self::menu($player, "Plugins - List - $group", "Choose plugin", $options);

		if(!is_null($formResult)) {
			$pluginName = $listPlugins[$formResult];
			$plugin = PluginsPool::getPluginCacheByName($pluginName);
			if($plugin !== null) {
				yield self::versionsForm($player, $plugin, $group);
			}
		}
	}

	public static function actionForm(Player $player, PluginCache $plugin, string $version, string $group) : Generator {
		$actionChoose = yield from self::menu($player, $plugin->getName(), "Choose a version", [
			new MenuOption("Show info"),
			new MenuOption("Install"),
		]);
		switch ($actionChoose) {
			case self::ACTION_INSTALL:
				$serverAPI = Server::getInstance()->getApiVersion();
				/** @var null|array{from: string, to: string} $versionAPI */
				$versionAPI = $plugin->getVersion($version)?->getAPI();
				if(is_null($versionAPI)) {
					return;
				}
				if (version_compare($versionAPI["from"], $serverAPI, ">=")) {
					$installAction = new InstallPlugin($player, $plugin->getName(), $version);
					$installAction->execute();
				} else {
					$pluginName = $plugin->getName();
					$pluginAPI = "{$versionAPI["from"]} -> {$versionAPI["to"]}";

					$modal = yield from self::modal(
						$player,
						"NO VERSION COMPARE",
						"$pluginName will encounter errors when installed on your server due to API incompatibility\nServer API: $serverAPI\n$pluginName API: $pluginAPI",
						"Continue",
						"Cancel"
					);

					if ($modal) {
						$installAction = new InstallPlugin($player, $pluginName, $version);
						$installAction->execute();
					}
				}

				break;
			case self::ACTION_SHOW:
				yield self::showForm($player, $plugin->getName(), $version, $group);
				break;
			default:
				yield self::versionsForm($player, $plugin, $group);
		}
	}

	public static function versionsForm(Player $player, PluginCache $plugin, string $group) : Generator {
		$versions = $plugin->getVersions();
		$versionChoose = yield from self::menu($player, $plugin->getName(), "Choose a version", array_map(function(string $version){
			return new MenuOption($version);
		}, $versions));
		if(!is_null($versionChoose)) {
			yield self::actionForm($player, $plugin, $versions[$versionChoose], $group);
		}
	}

	public static function showForm(Player $player, string $pluginName, string $pluginVersion, string $group) : Generator {
		$plugin = PluginsPool::getPluginCacheByName($pluginName);
		$version = $plugin?->getVersion($pluginVersion);
		if($plugin == null || $version == null) {
			return;
		}

		$pluginHomepage = $plugin->getHomePageByVersion($pluginVersion);
		$pluginLicense = $plugin->getLicense();
		$pluginDownloads = $plugin->getDownloads();
		$pluginScore = $plugin->getScore();
		$deps = array_map(function ($item) {
			/** @var array<string> $item */
			return $item["name"] . " v" . $item["version"];
		}, $version->getDepends());
		$deps = implode(", ", $deps);
		$size = $version->getSize();
		$pluginAPI = $version->getAPI();
		$descriptions = $version->getDescriptions();
		$infomation = "Version: $pluginVersion\nHomepage: $pluginHomepage\nLicense: $pluginLicense\nDownloads: $pluginDownloads\nScore: $pluginScore\nAPI: " . $pluginAPI["from"] . " <= PocketMine-MP <= " . $pluginAPI["to"] . "\nDepends: $deps\nDownload Size: $size";

		$formResult = yield from self::menu($player, $pluginName, $infomation . "\nDescriptions: ",
			array_map(function($keyDescription){
				return new MenuOption($keyDescription);
			}, array_keys($descriptions))
		);
		if(!is_null($formResult)) {
			$keyDescription = array_keys($descriptions)[$formResult];
			yield from self::custom($player, $keyDescription, [new Label("des", $descriptions[$keyDescription])]);
			yield from self::showForm($player, $pluginName, $pluginVersion, $group);
		}
	}

	/**
	 * @param CustomFormElement[] $elements
	 */
	public static function custom(Player $player, string $title, array $elements) : Generator {
		$f = yield Await::RESOLVE;
		$player->sendForm(new CustomForm(
			$title, $elements,
			function (Player $player, CustomFormResponse $result) use ($f) : void {
				$f($result);
			},
			function (Player $player) use ($f) : void {
				$f(null);
			}
		));
		return yield Await::ONCE;
	}

	/**
	 * @param MenuOption[] $options
	 */
	public static function menu(Player $player, string $title, string $text, array $options) : Generator {
		$f = yield Await::RESOLVE;
		$player->sendForm(new MenuForm(
			$title, $text, $options,
			function (Player $player, int $selectedOption) use ($f) : void {
				$f($selectedOption);
			},
			function (Player $player) use ($f) : void {
				$f(null);
			}
		));
		return yield Await::ONCE;
	}

	public static function modal(Player $player, string $title, string $text, string $yesButtonText = "gui.yes", string $noButtonText = "gui.no") : Generator {
		$f = yield Await::RESOLVE;
		$player->sendForm(new ModalForm(
			$title, $text,
			function (Player $player, bool $choice) use ($f) : void {
				$f($choice);
			},
			$yesButtonText, $noButtonText
		));
		return yield Await::ONCE;
	}
}
