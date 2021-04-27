<?php

declare(strict_types = 1);

namespace JavierLeon9966\Shield\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\world\sound\Sound;

class ShieldBlockSound extends Sound{

    public function encode(?Vector3 $pos): array{
        return [LevelSoundEventPacket::create(LevelSoundEventPacket::SOUND_ITEM_SHIELD_BLOCK, $pos)];
    }
}
