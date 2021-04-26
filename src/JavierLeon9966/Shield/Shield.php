<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield;

use alvin0319\Offhand\Offhand;

use pocketmine\block\BlockIds;
use pocketmine\entity\{Effect, Entity, Living};
use pocketmine\event\Listener;
use pocketmine\event\entity\{EntityDamageEvent, EntityDamageByChildEntityEvent, EntityDamageByEntityEvent};
use pocketmine\event\player\{PlayerAnimationEvent, PlayerToggleSneakEvent};
use pocketmine\item\{Axe, Item, ItemFactory};
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\network\mcpe\protocol\{AnimatePacket, LevelSoundEventPacket};
use pocketmine\scheduler\ClosureTask;

use JavierLeon9966\Shield\item\Shield as ShieldItem;

final class Shield extends PluginBase implements Listener{
	private $cooldowns = [];

	public function onEnable(): void{
		ItemFactory::registerItem(new ShieldItem);
		Item::addCreativeItem(Item::get(Item::SHIELD));
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	/**
	 * @priority MONITOR
	 * @ignoreCancelled
	 */
	public function onPlayerAnimation(PlayerAnimationEvent $event): void{
		$player = $event->getPlayer();
		if($event->getAnimationType() == AnimatePacket::ACTION_SWING_ARM){
			$ticks = 6;

			if($player->hasEffect(Effect::HASTE) and $player->getEffect(Effect::HASTE)->getEffectLevel() > 1){
				$ticks -= $player->getEffect(Effect::HASTE)->getEffectLevel();
			}elseif($player->hasEffect(Effect::FATIGUE)){
				$ticks += 2*$player->getEffect(Effect::FATIGUE)->getEffectLevel();
			}

			if($ticks <= 0) return;

			$player->setGenericFlag(Entity::DATA_FLAG_BLOCKING, false);
			$this->cooldowns[$player->getRawUniqueId()] = true;

			$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($player): void{
				$player->setGenericFlag(Entity::DATA_FLAG_BLOCKING, $player->isSneaking());
				unset($this->cooldowns[$player->getRawUniqueId()]);
			}), $ticks);
		}
	}

	/**
	 * @priority MONITOR
	 * @ignoreCancelled
	 */
	public function onPlayerToggleSneak(PlayerToggleSneakEvent $event): void{
		if(!isset($this->cooldowns[$event->getPlayer()->getRawUniqueId()])){
			$event->getPlayer()->setGenericFlag(Entity::DATA_FLAG_BLOCKING, $event->isSneaking());
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
			and $entity->getGenericFlag(Entity::DATA_FLAG_BLOCKING)
			and ($entity->getInventory()->getItemInHand() instanceof ShieldItem
			or Offhand::getInstance()->getOffhandInventory($entity)->getItem(0) instanceof ShieldItem)
			and $entity->canInteract($event instanceof EntityDamageByChildEntityEvent ? $event->getChild() : $damager, 8, 0)
		){
			$entity->getLevel()->broadcastLevelSoundEvent($entity, LevelSoundEventPacket::SOUND_ITEM_SHIELD_BLOCK);

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
				$entity->getLevel()->broadcastLevelSoundEvent($entity, LevelSoundEventPacket::SOUND_BREAK);
			}

			if(!$event instanceof EntityDamageByChildEntityEvent and $damager instanceof Living){
				$deltaX = $damager->x - $entity->x;
				$deltaZ = $damager->z - $entity->z;
				$damager->knockBack($entity, 0, $deltaX, $deltaZ, 0.8);
			}

			$event->setCancelled();
		}
	}
}
