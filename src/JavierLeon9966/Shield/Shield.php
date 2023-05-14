<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield;

use JavierLeon9966\Shield\item\Shield as ShieldItem;
use JavierLeon9966\Shield\sound\ShieldBlockSound;

use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\{Entity, Human, Living};
use pocketmine\event\Listener;
use pocketmine\event\entity\{EntityDamageByChildEntityEvent, EntityDamageEvent, EntityDamageByEntityEvent};
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\{Axe, ItemIdentifier, ItemIds, ItemFactory, StringToItemParser};
use pocketmine\network\mcpe\protocol\PlayerStartItemCooldownPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\sound\ItemBreakSound;

final class Shield extends PluginBase implements Listener{
	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $cooldowns = [];

	public function onEnable(): void{
		$shield = new ShieldItem(new ItemIdentifier(ItemIds::SHIELD, 0), 'Shield');
		ItemFactory::getInstance()->register($shield);
		CreativeInventory::getInstance()->add($shield);
		StringToItemParser::getInstance()->register('shield', static fn() => clone $shield);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function setCooldown(Player $player, int $ticks): void{
		$scheduler = $this->getScheduler();
		if(isset($this->cooldowns[$player->getUniqueId()->getBytes()])){
			return;
		}
		$networkProperties = $player->getNetworkProperties();
		if($player->isSneaking()){
			$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKING, false);
			$networkProperties->setGenericFlag(EntityMetadataFlags::TRANSITION_BLOCKING, true);
			$scheduler->scheduleTask(new ClosureTask(static fn() => $networkProperties->setGenericFlag(EntityMetadataFlags::TRANSITION_BLOCKING, false)));
		}
		$this->cooldowns[$player->getUniqueId()->getBytes()] = true;
		$scheduler->scheduleDelayedTask(new ClosureTask(fn() => $this->removeCooldown($player)), $ticks);
	}

	public function hasCooldown(Player $player): bool{
		return isset($this->cooldowns[$player->getUniqueId()->getBytes()]);
	}

	public function removeCooldown(Player $player): void{
		if(!isset($this->cooldowns[$player->getUniqueId()->getBytes()])){
			return;
		}
		$networkProperties = $player->getNetworkProperties();
		if($player->isSneaking()){
			$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKING, true);
			$networkProperties->setGenericFlag(EntityMetadataFlags::TRANSITION_BLOCKING, true);
			$this->getScheduler()->scheduleTask(new ClosureTask(static fn() => $networkProperties->setGenericFlag(EntityMetadataFlags::TRANSITION_BLOCKING, false)));
		}
		unset($this->cooldowns[$player->getUniqueId()->getBytes()]);
	}

	/**
	 * @priority MONITOR
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event): void{
		$player = $event->getOrigin()->getPlayer();
		if(!$player instanceof Player) return;

		$packet = $event->getPacket();
		if($packet instanceof AnimatePacket and $packet->action === AnimatePacket::ACTION_SWING_ARM){
			$ticks = 6;

			$effects = $player->getEffects();
			if(($effectLevel = $effects->get(VanillaEffects::HASTE())?->getEffectLevel() ?? 0) > 1){
				$ticks -= $effectLevel;
			}else{
				$ticks += 2*($effects->get(VanillaEffects::MINING_FATIGUE())?->getEffectLevel() ?? 0);
			}

			if($ticks > 0) $this->setCooldown($player, $ticks);
		}
	}

	/**
	 * @priority MONITOR
	 */
	public function onPlayerToggleSneak(PlayerToggleSneakEvent $event): void{
		$player = $event->getPlayer();
		if(isset($this->cooldowns[$player->getUniqueId()->getBytes()])){
			return;
		}
		$networkProperties = $player->getNetworkProperties();
		$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKING, $event->isSneaking());
		$networkProperties->setGenericFlag(EntityMetadataFlags::TRANSITION_BLOCKING, true);
		$this->getScheduler()->scheduleTask(new ClosureTask(static fn() => $networkProperties->setGenericFlag(EntityMetadataFlags::TRANSITION_BLOCKING, false)));
	}

	/**
	 * @priority HIGHEST
	 */
	public function onEntityDamageByEntity(EntityDamageByEntityEvent $event): void{
		$damager = $event instanceof EntityDamageByChildEntityEvent ? $event->getChild() : $event->getDamager();
		$entity = $event->getEntity();
		if(!$entity instanceof Player) return;

		$inventory = $entity->getInventory();
		$offhandInventory = $entity->getOffHandInventory();

		if(!$damager instanceof Entity) return;

		if($event->canBeReducedByArmor()
			and !isset($this->cooldowns[$entity->getUniqueId()->getBytes()])
			and $entity->isSneaking()
			and ($inventory->getItemInHand() instanceof ShieldItem
			or $offhandInventory->getItem(0) instanceof ShieldItem)
			and $entity->canInteract($damager->getPosition(), 8, 0)
		){
			if($damager instanceof Human && $damager->getInventory()->getItemInHand() instanceof Axe){
				$cooldownTicks = 5 * 20; // 5 seconds
				$this->setCooldown($entity, $cooldownTicks);
				$entity->getNetworkSession()->sendDataPacket(PlayerStartItemCooldownPacket::create('shield', $cooldownTicks));
				$entity->broadcastSound(new ItemBreakSound);
			}

			$entity->broadcastSound(new ShieldBlockSound);

			$damage = (int)(2*($event->getBaseDamage()+array_sum([
				$event->getModifier(EntityDamageEvent::MODIFIER_STRENGTH),
				$event->getModifier(EntityDamageEvent::MODIFIER_WEAKNESS),
				$event->getModifier(EntityDamageEvent::MODIFIER_CRITICAL),
				$event->getModifier(EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS)
			])));

			$damaged = false;
			$shield = $offhandInventory->getItem(0);
			if($shield instanceof ShieldItem){
				$damaged = $shield->getDamage() !== 0;
				$shield->applyDamage($damage);
				$offhandInventory->setItem(0, $shield);
			}else{
				$shield = $inventory->getItemInHand();
				if($shield instanceof ShieldItem){
					$damaged = $shield->getDamage() !== 0;
					$shield->applyDamage($damage);
					$inventory->setItemInHand($shield);
				}
			}

			$networkProperties = $entity->getNetworkProperties();
			$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKED_USING_SHIELD, true);
			if($damaged){
				$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKED_USING_DAMAGED_SHIELD, true);
			}

			$this->getScheduler()->scheduleTask(new ClosureTask(static function() use($networkProperties): void{
				$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKED_USING_SHIELD, false);
				$networkProperties->setGenericFlag(EntityMetadataFlags::BLOCKED_USING_DAMAGED_SHIELD, false);
			}));
			if($shield instanceof ShieldItem and $shield->isBroken()){
				$entity->broadcastSound(new ItemBreakSound);
			}

			if($damager instanceof Living){
				$damagerPos = $damager->getPosition();
				$entityPos = $entity->getPosition();
				$deltaX = $damagerPos->x - $entityPos->x;
				$deltaZ = $damagerPos->z - $entityPos->z;
				$damager->knockBack($deltaX, $deltaZ, 0.587382); //Vanilla shield knock back. I have no idea why is it this specific value
			}

			$event->cancel();
		}
	}
}
