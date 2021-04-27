<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield;

use alvin0319\Offhand\Offhand;

use pocketmine\block\BlockIds;
use pocketmine\entity\{Entity, Living, VanillaEffects};
use pocketmine\event\Listener;
use pocketmine\event\entity\{EntityDamageEvent, EntityDamageByChildEntityEvent, EntityDamageByEntityEvent};
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\{PlayerAnimationEvent, PlayerToggleSneakEvent};
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\{ItemIdentifier, ItemIds, ItemFactory};
use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\sound\ItemBreakSound;

use JavierLeon9966\Shield\item\Shield as ShieldItem;
use JavierLeon9966\Shield\sound\ShieldBlockSound;

final class Shield extends PluginBase implements Listener{
	private $cooldowns = [];

	public function onEnable(): void{
		ItemFactory::getInstance()->register(new ShieldItem(new ItemIdentifier(ItemIds::SHIELD, 0), 'Shield'));
		CreativeInventory::getInstance()->add(ItemFactory::getInstance()->get(ItemIds::SHIELD));
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	/**
	 * @priority MONITOR
	 * @ignoreCancelled
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event): void{
		$player = $event->getOrigin()->getPlayer();
		if($event->getPacket() instanceof AnimatePacket and $event->getPacket()->action === AnimatePacket::ACTION_SWING_ARM){
			$ticks = 6;

			if($player->getEffects()->has(VanillaEffects::HASTE()) and $player->getEffects()->get(VanillaEffects::HASTE())->getEffectLevel() > 1){
				$ticks -= $player->getEffects()->get(VanillaEffects::HASTE())->getEffectLevel();
			}elseif($player->getEffects()->has(VanillaEffects::MINING_FATIGUE())){
				$ticks += 2*$player->getEffects()->get(VanillaEffects::MINING_FATIGUE())->getEffectLevel();
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
		if(!isset($this->cooldowns[$event->getPlayer()->getUniqueId()->getBytes()])){
			$event->getPlayer()->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::BLOCKING, $event->isSneaking());
		}
	}

	/**
	 * @priority HIGHEST
	 * @ignoreCancelled
	 */
	public function onEntityDamageByEntity(EntityDamageByEntityEvent $event): void{
		$entity = $event->getEntity();
		$damager = $event->getDamager();
		if(!$damager instanceof Entity) return;
		if($entity instanceof Player
			and $event->canBeReducedByArmor()
			and $entity->getNetworkProperties()->getGenericFlag(EntityMetadataFlags::DATA_FLAG_BLOCKING)
			and ($entity->getInventory()->getItemInHand() instanceof ShieldItem
			or Offhand::getInstance()->getOffhandInventory($entity)->getItem(0) instanceof ShieldItem)
			and $entity->canInteract($event instanceof EntityDamageByChildEntityEvent ? $event->getChild() : $damager->getPosition(), 8, 0)
		){
			$entity->broadcastSound(new ShieldBlockSound);

			$damage = (int)(2*($event->getBaseDamage()+array_sum([
				$event->getModifier(EntityDamageEvent::MODIFIER_STRENGTH),
				$event->getModifier(EntityDamageEvent::MODIFIER_WEAKNESS),
				$event->getModifier(EntityDamageEvent::MODIFIER_CRITICAL),
				$event->getModifier(EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS)
			])));

			$shield = Offhand::getInstance()->getOffhandInventory($entity)->getItem(0);
			if($shield instanceof ShieldItem){
				$shield->applyDamage($damage);
				Offhand::getInstance()->getOffhandInventory($entity)->setItem(0, $shield);
			}else{
				$shield = $entity->getInventory()->getItemInHand();
				if($shield instanceof ShieldItem){
					$shield->applyDamage($damage);
					$entity->getInventory()->setItemInHand($shield);
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
