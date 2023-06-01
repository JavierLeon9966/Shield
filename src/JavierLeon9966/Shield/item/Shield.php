<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield\item;

use pocketmine\block\Block;
use pocketmine\item\Durable;
use pocketmine\item\Item;

class Shield extends Durable{

	public function getMaxStackSize(): int{
		return 1;
	}

	public function getMaxDurability(): int{
		return 337;
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	public function onDestroyBlock(Block $block, array &$returnedItems): bool{
		if(!$block->getBreakInfo()->breaksInstantly()){
			return $this->applyDamage(2);
		}
		return false;
	}
}
