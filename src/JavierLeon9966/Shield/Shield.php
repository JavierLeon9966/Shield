<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield;

use pocketmine\entity\{Entity, Human, Living, VanillaEffects};
use pocketmine\event\Listener;
use pocketmine\event\entity\{EntityDamageEvent, EntityDamageByChildEntityEvent, EntityDamageByEntityEvent};
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\{PlayerAnimationEvent, PlayerToggleSneakEvent};
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\{ItemIdentifier, ItemIds, ItemFactory};
use pocketmine\plugin\PluginBase;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\sound\ItemBreakSound;

use JavierLeon9966\Shield\item\Shield as ShieldItem;
use JavierLeon9966\Shield\sound\ShieldBlockSound;

final class Shield extends PluginBase implements Listener{
	private $cooldowns = [];

	public function onEnable(): void{
		$itemFactory = ItemFactory::getInstance();
		$itemFactory->register(new ShieldItem(new ItemIdentifier(ItemIds::SHIELD, 0), 'Shield'));
		CreativeInventory::getInstance()->add($itemFactory->get(ItemIds::SHIELD));
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	/**
	 * @priority MONITOR
	 * @ignoreCancelled
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event): void{
		$player = $event->getOrigin()->getPlayer();
		$packet = $event->getPacket();
		if($packet instanceof AnimatePacket and $packet->action === AnimatePacket::ACTION_SWING_ARM){
			$ticks = 6;

			$effects = $player->getEffects();
			if($effects->has(VanillaEffects::HASTE()) and $effects->get(VanillaEffects::HASTE())->getEffectLevel() > 1){
				$ticks -= $effects->get(VanillaEffects::HASTE())->getEffectLevel();
			}elseif($effects->has(VanillaEffects::MINING_FATIGUE())){
				$ticks += 2*$effects->get(VanillaEffects::MINING_FATIGUE())->getEffectLevel();
			}

			if($ticks <= 0) return;

			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::BLOCKING, false);
			$this->cooldowns[$player->getUniqueId()->getBytes()] = true;

			$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($player): void{
				$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::BLOCKING, $player->isSneaking());
				unset($this->cooldowns[$player->getUniqueId()->getBytes()]);
			}), $ticks);
		}
	}

	/**
	 * @priority MONITOR
	 * @ignoreCancelled
	 */
	public function onPlayerToggleSneak(PlayerToggleSneakEvent $event): void{
		$player = $event->getPlayer();
		if(!isset($this->cooldowns[$player->getUniqueId()->getBytes()])){
			$player->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::BLOCKING, $event->isSneaking());
		}
	}

	/**
	 * @priority HIGHEST
	 * @ignoreCancelled
	 */
	public function onEntityDamageByEntity(EntityDamageByEntityEvent $event): void{
		$damager = $event->getDamager();
		$entity = $event->getEntity();

		$inventory = $entity->getInventory();
		$offhandInventory = $entity->getOffHandInventory();

		if(!$damager instanceof Entity) return;

		if($entity instanceof Human
			and $event->canBeReducedByArmor()
			and $entity->getNetworkProperties()->getGenericFlag(EntityMetadataFlags::DATA_FLAG_BLOCKING)
			and ($inventory->getItemInHand() instanceof ShieldItem
			or $offhandInventory->getItem(0) instanceof ShieldItem)
			and $entity->canInteract($event instanceof EntityDamageByChildEntityEvent ? $event->getChild() : $damager->getPosition(), 8, 0)
		){
			$entity->broadcastSound(new ShieldBlockSound);

			$damage = (int)(2*($event->getBaseDamage()+array_sum([
				$event->getModifier(EntityDamageEvent::MODIFIER_STRENGTH),
				$event->getModifier(EntityDamageEvent::MODIFIER_WEAKNESS),
				$event->getModifier(EntityDamageEvent::MODIFIER_CRITICAL),
				$event->getModifier(EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS)
			])));

			$shield = $offhandInventory->getItem(0);
			if($shield instanceof ShieldItem){
				$shield->applyDamage($damage);
				$offhandInventory->setItem(0, $shield);
			}else{
				$shield = $inventory->getItemInHand();
				if($shield instanceof ShieldItem){
					$shield->applyDamage($damage);
					$inventory->setItemInHand($shield);
				}
			}

			if($shield->isBroken()){
				$entity->broadcastSound(new ItemBreakSound);
			}

			if(!$event instanceof EntityDamageByChildEntityEvent and $damager instanceof Living){
				$deltaX = $damager->getPosition()->x - $entity->getPosition()->x;
				$deltaZ = $damager->getPosition()->z - $entity->getPosition()->z;
				$damager->knockBack($deltaX, $deltaZ, 0.8);
			}

			$event->cancel();
		}
	}
}
