<?php

declare(strict_types=1);

namespace skh6075\SkinStorage;

use kim\present\converter\png\PngConverter;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Filesystem;
use pocketmine\utils\TextFormat;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;

final class SkinStorageLoader extends PluginBase{
	/**
	 * @phpstan-var array<string, Skin>
	 * @var Skin[]
	 */
	private array $skinListMap = [];

	protected function onEnable() : void{
		if(!class_exists(PngConverter::class)){
			$this->getLogger()->critical("The PngConverter library could not be found. \"https://github.com/presentkim-pm/png-converter\" Please install this virion.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		if(file_exists($file = Path::join($this->getDataFolder(), "skinList.dat"))){
			$reader = new LittleEndianNbtSerializer();
			try{
				$nbt = $reader->read(file_get_contents($file))->mustGetCompoundTag();
			}catch(NbtDataException $exception){
				throw new \RuntimeException($exception->getMessage());
			}

			foreach($nbt->getValue() as $skinName => $skinTag){
				if(!$skinTag instanceof CompoundTag){
					continue;
				}

				$this->skinListMap[$skinName] = Human::parseSkinNBT($skinTag);
			}
		}
	}

	protected function onDisable() : void{
		$nbt = CompoundTag::create();
		foreach($this->skinListMap as $skinName => $skin){
			$nbt->setTag($skinName, CompoundTag::create()->setTag("Skin", CompoundTag::create()
				->setString("Name", $skin->getSkinId())
				->setByteArray("Data", $skin->getSkinData())
				->setByteArray("CapeData", $skin->getCapeData())
				->setString("GeometryName", $skin->getGeometryName())
				->setByteArray("GeometryData", $skin->getGeometryData())));
		}

		$writer = new LittleEndianNbtSerializer();
		Filesystem::safeFilePutContents(Path::join($this->getDataFolder(), "skinList.dat"), $writer->write(new TreeRoot($nbt)));
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!$sender instanceof Player || !$command->testPermission($sender)){
			return false;
		}

		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}

		switch(array_shift($args)){
			case "load":
				if(count($args) < 3){
					$sender->sendMessage(TextFormat::YELLOW . "/skin load <json filePath> <png filePath> <geometryName>");
					return false;
				}

				$jsonFilePath = $this->reprocess(array_shift($args));
				$pngFilePath = $this->reprocess(array_shift($args));
				$geometryName = array_shift($args);
				if(!file_exists($jsonFilePath) || !file($pngFilePath)){
					$sender->sendMessage(TextFormat::RED . "File not found");
					return false;
				}

				if(!str_starts_with($geometryName, "geometry.")){
					$geometryName = "geometry.$geometryName";
				}

				$skinImage = PngConverter::toSkinImageFromFile($pngFilePath);
				$sender->setSkin(new Skin(
					skinId: Uuid::uuid4()->toString(),
					skinData: $skinImage->getData(),
					capeData: "",
					geometryName: $geometryName,
					geometryData: file_get_contents($jsonFilePath)
				));
				$sender->sendSkin();
				$sender->sendMessage(TextFormat::AQUA . "Skin loaded successfully");
				break;
			case "save":
				if(count($args) < 1){
					$sender->sendMessage(TextFormat::YELLOW . "/skin save <name>");
					return false;
				}

				$name = array_shift($args);
				if(isset($this->skinListMap[$name])){
					$sender->sendMessage(TextFormat::RED . "A skin saved under the name $name already exists.");
					return false;
				}

				$this->skinListMap[$name] = $sender->getSkin();
				$sender->sendMessage(TextFormat::AQUA . "Saved my skin as $name.");
				break;
			case "test":
				if(count($args) < 1){
					$sender->sendMessage(TextFormat::YELLOW . "/skin test <name>");
					return false;
				}

				$name = array_shift($args);
				if(!isset($this->skinListMap[$name])){
					$sender->sendMessage(TextFormat::RED . "Skin saved under $name not found");
					return false;
				}

				$sender->setSkin($this->skinListMap[$name]);
				$sender->sendSkin();
				break;
			default:
				throw new InvalidCommandSyntaxException();
		}

		return true;
	}

	public function getSkin(string $name): ?Skin{
		return $this->skinListMap[$name] ?? null;
	}

	private function reprocess(string $path): string{
		return Path::join($this->getServer()->getDataPath(), $path);
	}
}
