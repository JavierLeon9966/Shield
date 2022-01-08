<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield\item;

use pocketmine\block\Block;
use pocketmine\item\Durable;

class Shield extends Durable{

	public function getMaxStackSize(): int{
		return 1;
	}

	public function getMaxDurability(): int{
		return 337;
	}

	public function onDestroyBlock(Block $block): bool{
		if(!$block->getBreakInfo()->breaksInstantly()){
			return $this->applyDamage(2);
		}
		return false;
	}
}
