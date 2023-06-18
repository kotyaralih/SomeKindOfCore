<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

/**
 * All the entity classes
 */
namespace pocketmine\entity;

use pocketmine\block\Water;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Timings;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\FullChunk;
use pocketmine\level\Level;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Math;
use pocketmine\math\Vector3 as Vector3;
use pocketmine\metadata\Metadatable;
use pocketmine\metadata\MetadataValue;
use pocketmine\nbt\tag\Byte;
use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\Double;
use pocketmine\nbt\tag\Enum;
use pocketmine\nbt\tag\Float;
use pocketmine\nbt\tag\Short;
use pocketmine\Network;
use pocketmine\network\protocol\MoveEntityPacket;
use pocketmine\network\protocol\MovePlayerPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\network\protocol\SetEntityDataPacket;
use pocketmine\network\protocol\SetEntityMotionPacket;
use pocketmine\network\protocol\SetTimePacket;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\Server;

abstract class Entity extends Location implements Metadatable{

	const NETWORK_ID = -1;

	public static $entityCount = 1;

	/**
	 * @var Player[]
	 */
	protected $hasSpawned = [];

	protected $id;

	public $passenger = null;
	public $vehicle = null;

	/** @var int */
	public $chunkX;
	/** @var int */
	public $chunkZ;

	/** @var Chunk */
	public $chunk;

	protected $lastDamageCause = null;

	public $lastX;
	public $lastY;
	public $lastZ;

	public $motionX;
	public $motionY;
	public $motionZ;
	public $lastMotionX;
	public $lastMotionY;
	public $lastMotionZ;

	public $lastYaw;
	public $lastPitch;

	/** @var AxisAlignedBB */
	public $boundingBox;
	public $onGround;
	public $inBlock = false;
	public $positionChanged;
	public $motionChanged;
	public $dead;
	protected $age = 0;

	public $height;

	public $eyeHeight = null;

	public $width;
	public $length;

	/** @var int */
	private $health = 20;
	private $maxHealth = 20;

	protected $ySize = 0;
	protected $stepHeight = 0;
	public $keepMovement = true;

	public $fallDistance;
	public $ticksLived;
	public $lastUpdate;
	public $maxFireTicks;
	public $fireTicks;
	public $airTicks;
	public $namedtag;
	public $canCollide = true;

	protected $isStatic = false;
	protected $isColliding = false;

	public $noDamageTicks;
	private $justCreated;
	protected $fireProof;
	private $invulnerable;

	protected $gravity;
	protected $drag;

	/** @var Server */
	protected $server;

	public $closed = false;

	/** @var \pocketmine\event\TimingsHandler */
	protected $timings;


	public function __construct(FullChunk $chunk, Compound $nbt){
		if($chunk === null or $chunk->getProvider() === null){
			throw new \Exception("Invalid garbage Chunk given to Entity");
		}

		$this->timings = Timings::getEntityTimings($this);

		if($this->eyeHeight === null){
			$this->eyeHeight = $this->height / 2 + 0.1;
		}

		$this->id = Entity::$entityCount++;
		$this->justCreated = true;
		$this->namedtag = $nbt;
		$this->chunk = $chunk;
		$this->setLevel($chunk->getProvider()->getLevel());
		$this->server = $chunk->getProvider()->getLevel()->getServer();

		$this->boundingBox = new AxisAlignedBB(0, 0, 0, 0, 0, 0);
		$this->setPositionAndRotation(
			new Vector3(
				$this->namedtag["Pos"][0],
				$this->namedtag["Pos"][1],
				$this->namedtag["Pos"][2]
			),
			$this->namedtag->Rotation[0],
			$this->namedtag->Rotation[1],
			true
		);
		$this->setMotion(new Vector3($this->namedtag["Motion"][0], $this->namedtag["Motion"][1], $this->namedtag["Motion"][2]));

		if(!isset($this->namedtag->FallDistance)){
			$this->namedtag->FallDistance = new Float("FallDistance", 0);
		}
		$this->fallDistance = $this->namedtag["FallDistance"];

		if(!isset($this->namedtag->Fire)){
			$this->namedtag->Fire = new Short("Fire", 0);
		}
		$this->fireTicks = $this->namedtag["Fire"];

		if(!isset($this->namedtag->Air)){
			$this->namedtag->Air = new Short("Air", 300);
		}
		$this->airTicks = $this->namedtag["Air"];

		if(!isset($this->namedtag->OnGround)){
			$this->namedtag->OnGround = new Byte("OnGround", 0);
		}
		$this->onGround = $this->namedtag["OnGround"] > 0 ? true : false;

		if(!isset($this->namedtag->Invulnerable)){
			$this->namedtag->Invulnerable = new Byte("Invulnerable", 0);
		}
		$this->invulnerable = $this->namedtag["Invulnerable"] > 0 ? true : false;

		$this->chunk->addEntity($this);
		$this->level->addEntity($this);
		$this->initEntity();
		$this->lastUpdate = $this->server->getTick();
		$this->server->getPluginManager()->callEvent(new EntitySpawnEvent($this));

		$this->scheduleUpdate();

	}

	public function saveNBT(){
		$this->namedtag->Pos = new Enum("Pos", [
			new Double(0, $this->x),
			new Double(1, $this->y),
			new Double(2, $this->z)
		]);

		$this->namedtag->Motion = new Enum("Motion", [
			new Double(0, $this->motionX),
			new Double(1, $this->motionY),
			new Double(2, $this->motionZ)
		]);

		$this->namedtag->Rotation = new Enum("Rotation", [
			new Float(0, $this->yaw),
			new Float(1, $this->pitch)
		]);

		$this->namedtag->FallDistance = new Float("FallDistance", $this->fallDistance);
		$this->namedtag->Fire = new Short("Fire", $this->fireTicks);
		$this->namedtag->Air = new Short("Air", $this->airTicks);
		$this->namedtag->OnGround = new Byte("OnGround", $this->onGround == true ? 1 : 0);
		$this->namedtag->Invulnerable = new Byte("Invulnerable", $this->invulnerable == true ? 1 : 0);
	}

	protected abstract function initEntity();

	/**
	 * @return Player[]
	 */
	public function getViewers(){
		return $this->hasSpawned;
	}

	/**
	 * @param Player $player
	 */
	public function spawnTo(Player $player){
		if(!isset($this->hasSpawned[$player->getID()]) and isset($player->usedChunks[Level::chunkHash($this->chunk->getX(), $this->chunk->getZ())])){
			$this->hasSpawned[$player->getID()] = $player;
		}
	}

	/**
	 * @param Player[]|Player $player
	 */
	public function sendMetadata($player){
		if($player instanceof Player){
			$player = [$player];
		}

		$pk = new SetEntityDataPacket();
		$pk->eid = $this->id;
		$pk->metadata = $this->getData();
		$pk->encode();
		$pk->isEncoded = true;
		foreach($player as $p){
			if($p === $this){
				/** @var Player $p */
				$pk2 = new SetEntityDataPacket();
				$pk2->eid = 0;
				$pk2->metadata = $this->getData();
				$p->dataPacket($pk2);
			}else{
				$p->dataPacket($pk);
			}
		}
	}

	/**
	 * @param Player $player
	 */
	public function despawnFrom(Player $player){
		if(isset($this->hasSpawned[$player->getID()])){
			$pk = new RemoveEntityPacket;
			$pk->eid = $this->id;
			$player->dataPacket($pk);
			unset($this->hasSpawned[$player->getID()]);
		}
	}

	/**
	 * @param float                 $damage
	 * @param int|EntityDamageEvent $source
	 *
	 */
	abstract function attack($damage, $source = EntityDamageEvent::CAUSE_MAGIC);

	/**
	 * @param float                       $amount
	 * @param int|EntityRegainHealthEvent $source
	 *
	 */
	abstract function heal($amount, $source = EntityRegainHealthEvent::CAUSE_MAGIC);

	/**
	 * @return int
	 */
	public function getHealth(){
		return $this->health;
	}

	/**
	 * Sets the health of the Entity. This won't send any update to the players
	 *
	 * @param int $amount
	 */
	public function setHealth($amount){
		if($amount === $this->health){
			return;
		}

		if($amount <= 0){
			$this->health = 0;
			if($this->dead !== true){
				$this->kill();
			}
		}elseif($amount > $this->getMaxHealth()){
			$this->health = $this->getMaxHealth();
		}else{
			$this->health = (int) $amount;
		}
	}

	/**
	 * @param int|EntityDamageEvent $type
	 */
	public function setLastDamageCause($type){
		$this->lastDamageCause = $type;
	}

	/**
	 * @return int|EntityDamageEvent|null
	 */
	public function getLastDamageCause(){
		return $this->lastDamageCause;
	}

	/**
	 * @return int
	 */
	public function getMaxHealth(){
		return $this->maxHealth;
	}

	/**
	 * @param int $amount
	 */
	public function setMaxHealth($amount){
		$this->maxHealth = (int) $amount;
		$this->health = (int) min($this->health, $this->maxHealth);
	}

	public function canCollideWith(Entity $entity){
		return !$this->justCreated and $entity !== $this;
	}

	protected function checkObstruction($x, $y, $z){
		$i = (int) $x;
		$j = (int) $y;
		$k = (int) $z;

		$diffX = $x - $i;
		$diffY = $y - $j;
		$diffZ = $z - $k;

		$list = $this->level->getCollisionBlocks($this->boundingBox);

		if(count($list) === 0 and !$this->level->isFullBlock(new Vector3($i, $j, $k))){
			return false;
		}else{
			$flag = !$this->level->isFullBlock(new Vector3($i - 1, $j, $k));
			$flag1 = !$this->level->isFullBlock(new Vector3($i + 1, $j, $k));
			//$flag2 = !$this->level->isFullBlock(new Vector3($i, $j - 1, $k));
			$flag3 = !$this->level->isFullBlock(new Vector3($i, $j + 1, $k));
			$flag4 = !$this->level->isFullBlock(new Vector3($i, $j, $k - 1));
			$flag5 = !$this->level->isFullBlock(new Vector3($i, $j, $k + 1));

			$direction = 3; //UP!
			$limit = 9999;

			if($flag){
				$limit = $diffX;
				$direction = 0;
			}

			if($flag1 and 1 - $diffX < $limit){
				$limit = 1 - $diffX;
				$direction = 1;
			}

			if($flag3 and 1 - $diffY < $limit){
				$limit = 1 - $diffY;
				$direction = 3;
			}

			if($flag4 and $diffZ < $limit){
				$limit = $diffZ;
				$direction = 4;
			}

			if($flag5 and 1 - $diffZ < $limit){
				$direction = 5;
			}

			$force = lcg_value() * 0.2 + 0.1;

			if($direction === 0){
				$this->motionX = -$force;

				return true;
			}

			if($direction === 1){
				$this->motionX = $force;

				return true;
			}

			//No direction 2

			if($direction === 3){
				$this->motionY = $force;

				return true;
			}

			if($direction === 4){
				$this->motionZ = -$force;

				return true;
			}

			if($direction === 5){
				$this->motionY = $force;
			}

			return true;

		}
	}

	public function entityBaseTick($tickDiff = 1){

		Timings::$tickEntityTimer->startTiming();
		//TODO: check vehicles

		$this->justCreated = false;
		$isPlayer = $this instanceof Player;

		if($this->dead === true){
			$this->despawnFromAll();
			if(!$isPlayer){
				$this->close();
			}
			Timings::$tickEntityTimer->stopTiming();
			return $isPlayer;
		}

		$hasUpdate = false;

		$this->checkBlockCollision();

		if($this->y < 0 and $this->dead !== true){
			$this->server->getPluginManager()->callEvent($ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_VOID, 10));
			if(!$ev->isCancelled()){
				$this->attack($ev->getFinalDamage(), $ev);
			}
			$hasUpdate = true;
		}

		if($this->fireTicks > 0){
			if($this->fireProof){
				$this->fireTicks -= 4 * $tickDiff;
				if($this->fireTicks < 0){
					$this->fireTicks = 0;
				}
			}else{
				if(($this->fireTicks % 20) === 0 or $tickDiff > 20){
					$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FIRE_TICK, 1);
					$this->server->getPluginManager()->callEvent($ev);
					if(!$ev->isCancelled()){
						$this->attack($ev->getFinalDamage(), $ev);
					}
				}
				$this->fireTicks -= $tickDiff;
			}

			if($this->fireTicks <= 0){
				$this->extinguish();
			}else{
				$hasUpdate = true;
			}
		}

		$this->age += $tickDiff;
		$this->ticksLived += $tickDiff;

		Timings::$tickEntityTimer->stopTiming();

		return $hasUpdate;
	}

	public function updateMovement(){
		if($this->x !== $this->lastX or $this->y !== $this->lastY or $this->z !== $this->lastZ or $this->yaw !== $this->lastYaw or $this->pitch !== $this->lastPitch){
			$this->lastX = $this->x;
			$this->lastY = $this->y;
			$this->lastZ = $this->z;

			$this->lastYaw = $this->yaw;
			$this->lastPitch = $this->pitch;

			if($this instanceof Human){
				$pk = new MovePlayerPacket;
				$pk->eid = $this->id;
				$pk->x = $this->x;
				$pk->y = $this->y;
				$pk->z = $this->z;
				$pk->yaw = $this->yaw;
				$pk->pitch = $this->pitch;
				$pk->bodyYaw = $this->yaw;
			}else{
				//TODO: add to move list
				$pk = new MoveEntityPacket();
				$pk->entities = [
					[$this->id, $this->x, $this->y + $this->getEyeHeight(), $this->z, $this->yaw, $this->pitch]
				];
			}

			Server::broadcastPacket($this->hasSpawned, $pk);
		}

		if(($this->lastMotionX != $this->motionX or $this->lastMotionY != $this->motionY or $this->lastMotionZ != $this->motionZ)){
			$this->lastMotionX = $this->motionX;
			$this->lastMotionY = $this->motionY;
			$this->lastMotionZ = $this->motionZ;

			$pk = new SetEntityMotionPacket;
			$pk->entities = [
				[$this->getID(), $this->motionX, $this->motionY, $this->motionZ]
			];

			Server::broadcastPacket($this->hasSpawned, $pk);

			if($this instanceof Player){
				$this->motionX = 0;
				$this->motionY = 0;
				$this->motionZ = 0;
			}
		}
	}

	/**
	 * @return Vector3
	 */
	public function getDirectionVector(){
		$pitch = ($this->pitch * M_PI) / 180;
		$yaw = (($this->yaw + 90) * M_PI) / 180;

		$y = -sin(deg2rad($this->pitch));
		$xz = cos(deg2rad($this->pitch));
		$x = -$xz * sin(deg2rad($this->yaw));
		$z = $xz * cos(deg2rad($this->yaw));

		return new Vector3($x, $y, $z);
	}

	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}

		$tickDiff = max(1, $currentTick - $this->lastUpdate);
		$this->lastUpdate = $currentTick;

		$this->timings->startTiming();

		$hasUpdate = $this->entityBaseTick($tickDiff);

		$this->updateMovement();

		$this->timings->stopTiming();

		//if($this->isStatic())
		return $hasUpdate;
		//return !($this instanceof Player);
	}

	public final function scheduleUpdate(){
		$this->level->updateEntities[$this->id] = $this;
	}

	public function setOnFire($seconds){
		$ticks = $seconds * 20;
		if($ticks > $this->fireTicks){
			$this->fireTicks = $ticks;
		}

		$this->sendMetadata($this->hasSpawned);
		if($this instanceof Player){
			$this->sendMetadata($this);
		}
	}

	public function getDirection(){
		$rotation = ($this->yaw - 90) % 360;
		if($rotation < 0){
			$rotation += 360.0;
		}
		if((0 <= $rotation and $rotation < 45) or (315 <= $rotation and $rotation < 360)){
			return 2; //North
		}elseif(45 <= $rotation and $rotation < 135){
			return 3; //East
		}elseif(135 <= $rotation and $rotation < 225){
			return 0; //South
		}elseif(225 <= $rotation and $rotation < 315){
			return 1; //West
		}else{
			return null;
		}
	}

	public function extinguish(){
		$this->fireTicks = 0;
		$this->sendMetadata($this->hasSpawned);
		if($this instanceof Player){
			$this->sendMetadata($this);
		}
	}

	public function canTriggerWalking(){
		return true;
	}

	protected function updateFallState($distanceThisTick, $onGround){
		if($onGround === true){
			if($this->fallDistance > 0){
				if($this instanceof Living){
					//TODO
				}

				$this->fall($this->fallDistance);
				$this->fallDistance = 0;
			}
		}elseif($distanceThisTick < 0){
			$this->fallDistance -= $distanceThisTick;
		}
	}

	public function getBoundingBox(){
		return $this->boundingBox;
	}

	public function fall($fallDistance){
		$damage = floor($fallDistance - 3);
		if($damage > 0){
			$this->server->getPluginManager()->callEvent($ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FALL, $damage));
			if($ev->isCancelled()){
				return;
			}
			$this->attack($ev->getFinalDamage(), $ev);
		}
	}

	public function handleLavaMovement(){ //TODO

	}

	public function getEyeHeight(){
		return $this->eyeHeight;
	}

	public function moveFlying(){ //TODO

	}

	public function onCollideWithPlayer(Human $entityPlayer){

	}

	protected function switchLevel(Level $targetLevel){
		if($this->isValid()){
			$this->server->getPluginManager()->callEvent($ev = new EntityLevelChangeEvent($this, $this->level, $targetLevel));
			if($ev->isCancelled()){
				return false;
			}

			$this->level->removeEntity($this);
			$this->chunk->removeEntity($this);
			$this->despawnFromAll();
			if($this instanceof Player){
				foreach($this->usedChunks as $index => $d){
					$X = null;
					$Z = null;
					Level::getXZ($index, $X, $Z);
					foreach($this->level->getChunkEntities($X, $Z) as $entity){
						$entity->despawnFrom($this);
					}

				}
				$this->level->freeAllChunks($this);
			}
		}
		$this->setLevel($targetLevel, $this instanceof Player ? true : false); //Hard reference
		$this->level->addEntity($this);
		if($this instanceof Player){
			$this->usedChunks = [];
			$pk = new SetTimePacket();
			$pk->time = $this->level->getTime();
			$pk->started = $this->level->stopTime == false;
			$this->dataPacket($pk);
		}
		$this->chunk = null;

		return true;
	}

	public function getPosition(){
		return new Position($this->x, $this->y, $this->z, $this->level);
	}

	public function getLocation(){
		return new Location($this->x, $this->y, $this->z, $this->yaw, $this->pitch, $this->level);
	}

	public function isInsideOfWater(){
		$block = $this->level->getBlock($pos = (new Vector3($this->x, $y = ($this->y + $this->getEyeHeight()), $this->z))->floor());

		if($block instanceof Water){
			$f = ($pos->y + 1) - ($block->getFluidHeightPercent() - 0.1111111);
			return $y < $f;
		}

		return false;
	}

	public function isInsideOfSolid(){
		$block = $this->level->getBlock($pos = (new Vector3($this->x, $y = ($this->y + $this->getEyeHeight()), $this->z))->floor());

		$bb = $block->getBoundingBox();

		if($bb !== null and $block->isSolid and !$block->isTransparent and $bb->intersectsWith($this->getBoundingBox())){
			return true;
		}
		return false;
	}

	public function collision(){
		$this->isColliding = true;
		$this->fallDistance = 0;
	}

	public function move($dx, $dy, $dz){

		if($dx == 0 and $dz == 0 and $dy == 0){
			return true;
		}

		//if($this->inBlock){ //TODO: noclip
		//	$this->boundingBox->offset($dx, $dy, $dz);
		//	$this->x = ($this->boundingBox->minX + $this->boundingBox->maxX) / 2;
		//	$this->y = $this->boundingBox->minY/* + $this->height*/;
		//	$this->z = ($this->boundingBox->minZ + $this->boundingBox->maxZ) / 2;
		//}else{

			Timings::$entityMoveTimer->startTiming();

			$this->ySize *= 0.4;

			/*
			if($this->isColliding){ //With cobweb?
				$this->isColliding = false;
				$dx *= 0.25;
				$dy *= 0.05;
				$dz *= 0.25;
				$this->motionX = 0;
				$this->motionY = 0;
				$this->motionZ = 0;
			}
			*/

			$movX = $dx;
			$movY = $dy;
			$movZ = $dz;

			$axisalignedbb = clone $this->boundingBox;

			/*$sneakFlag = $this->onGround and $this instanceof Player;

			if($sneakFlag){
				for($mov = 0.05; $dx != 0.0 and count($this->level->getCollisionCubes($this, $this->boundingBox->getOffsetBoundingBox($dx, -1, 0))) === 0; $movX = $dx){
					if($dx < $mov and $dx >= -$mov){
						$dx = 0;
					}elseif($dx > 0){
						$dx -= $mov;
					}else{
						$dx += $mov;
					}
				}

				for(; $dz != 0.0 and count($this->level->getCollisionCubes($this, $this->boundingBox->getOffsetBoundingBox(0, -1, $dz))) === 0; $movZ = $dz){
					if($dz < $mov and $dz >= -$mov){
						$dz = 0;
					}elseif($dz > 0){
						$dz -= $mov;
					}else{
						$dz += $mov;
					}
				}

				//TODO: big messy loop
			}*/

			$list = $this->level->getCollisionCubes($this, $this->boundingBox->getOffsetBoundingBox($dx, $dy, $dz), false);


			foreach($list as $bb){
				$dy = $bb->calculateYOffset($this->boundingBox, $dy);
			}

			$this->boundingBox->offset(0, $dy, 0);

			if(!$this->keepMovement and $movY != $dy){
				$dx = 0;
				$dy = 0;
				$dz = 0;
			}

			$fallingFlag = ($this->onGround or ($dy != $movY and $movY < 0));

			foreach($list as $bb){
				$dx = $bb->calculateXOffset($this->boundingBox, $dx);
			}

			$this->boundingBox->offset($dx, 0, 0);

			if(!$this->keepMovement and $movX != $dx){
				$dx = 0;
				$dy = 0;
				$dz = 0;
			}

			foreach($list as $bb){
				$dz = $bb->calculateZOffset($this->boundingBox, $dz);
			}

			$this->boundingBox->offset(0, 0, $dz);

			if(!$this->keepMovement and $movZ != $dz){
				$dx = 0;
				$dy = 0;
				$dz = 0;
			}


			if($this->stepHeight > 0 and $fallingFlag and $this->ySize < 0.05 and ($movX != $dx or $movZ != $dz)){
				$cx = $dx;
				$cy = $dy;
				$cz = $dz;
				$dx = $movX;
				$dy = $this->stepHeight;
				$dz = $movZ;

				$axisalignedbb1 = clone $this->boundingBox;

				$this->boundingBox->setBB($axisalignedbb);

				$list = $this->level->getCollisionCubes($this, $this->boundingBox->getOffsetBoundingBox($movX, $dy, $movZ), false);

				foreach($list as $bb){
					$dy = $bb->calculateYOffset($this->boundingBox, $dy);
				}

				$this->boundingBox->offset(0, $dy, 0);
				if(!$this->keepMovement and $movY != $dy){
					$dx = 0;
					$dy = 0;
					$dz = 0;
				}

				foreach($list as $bb){
					$dx = $bb->calculateXOffset($this->boundingBox, $dx);
				}

				$this->boundingBox->offset($dx, 0, 0);
				if(!$this->keepMovement and $movX != $dx){
					$dx = 0;
					$dy = 0;
					$dz = 0;
				}

				foreach($list as $bb){
					$dz = $bb->calculateZOffset($this->boundingBox, $dz);
				}

				$this->boundingBox->offset(0, 0, $dz);
				if(!$this->keepMovement and $movZ != $dz){
					$dx = 0;
					$dy = 0;
					$dz = 0;
				}

				if(!$this->keepMovement and $movY != $dy){
					$dx = 0;
					$dy = 0;
					$dz = 0;
				}else{
					$dy = -$this->stepHeight;
					foreach($list as $bb){
						$dy = $bb->calculateYOffset($this->boundingBox, $dy);
					}
					$this->boundingBox->offset(0, $dy, 0);
				}

				if(($cx ** 2 + $cz ** 2) >= ($dx ** 2 + $dz ** 2)){
					$dx = $cx;
					$dy = $cy;
					$dz = $cz;
					$this->boundingBox->setBB($axisalignedbb1);
				}

			}

			$pos = new Vector3(
				($this->boundingBox->minX + $this->boundingBox->maxX) / 2,
				$this->boundingBox->minY - $this->ySize,
				($this->boundingBox->minZ + $this->boundingBox->maxZ) / 2
			);

			$result = true;

			if(!$this->setPosition($pos)){
				$this->boundingBox->setBB($axisalignedbb);
				$result = false;
			}else{

				if($this instanceof Player){
					if(($this->onGround and $movY != 0) or (!$this->onGround and $movY < 0)){
						$bb = clone $this->boundingBox;
						$bb->maxY = $bb->minY + 0.5;
						if(count($this->level->getCollisionBlocks($bb->expand(0.01, 0.01, 0.01))) > 0){
							$isColliding = true;
						}else{
							$isColliding = false;
						}

						$this->onGround = ($movY <= 0 and $isColliding);
					}
				}else{
					$this->onGround = ($movY != $dy and $movY < 0);
				}
				$this->updateFallState($dy, $this->onGround);

				if($movX != $dx){
					$this->motionX = 0;
				}

				if($movY != $dy){
					$this->motionY = 0;
				}

				if($movZ != $dz){
					$this->motionZ = 0;
				}
			}

			//TODO: vehicle collision events (first we need to spawn them!)

			Timings::$entityMoveTimer->stopTiming();

			return $result;
		//}
	}

	protected function checkBlockCollision(){
		$minX = Math::floorFloat($this->boundingBox->minX + 0.001);
		$minY = Math::floorFloat($this->boundingBox->minY + 0.001);
		$minZ = Math::floorFloat($this->boundingBox->minZ + 0.001);
		$maxX = Math::floorFloat($this->boundingBox->maxX - 0.001);
		$maxY = Math::floorFloat($this->boundingBox->maxY - 0.001);
		$maxZ = Math::floorFloat($this->boundingBox->maxZ - 0.001);

		$vector = new Vector3(0, 0, 0);

		for($z = $minZ; $z <= $maxZ; ++$z){
			for($x = $minX; $x <= $maxX; ++$x){
				for($y = $minY; $y <= $maxY; ++$y){
					$block = $this->level->getBlock(new Vector3($x, $y, $z));
					if($block !== null and $block->hasEntityCollision){
						$block->onEntityCollide($this);
						if(!($this instanceof Player)){
							$block->addVelocityToEntity($this, $vector);
						}
					}
				}
			}
		}

		if(!($this instanceof Player) and $vector->length() > 0){
			$vector = $vector->normalize();
			$d = 0.014;
			$this->motionX += $vector->x * $d;
			$this->motionY += $vector->y * $d;
			$this->motionZ += $vector->z * $d;
		}
	}

	public function setPositionAndRotation(Vector3 $pos, $yaw, $pitch){
		if($this->setPosition($pos) === true){
			$this->setRotation($yaw, $pitch);

			return true;
		}

		return false;
	}

	public function setRotation($yaw, $pitch){
		$this->yaw = $yaw;
		$this->pitch = $pitch;
		$this->scheduleUpdate();
	}

	public function setPosition(Vector3 $pos){
		if($pos instanceof Position and $pos->level instanceof Level and $pos->level !== $this->level){
			if($this->switchLevel($pos->getLevel()) === false){
				return false;
			}
		}

		$this->x = $pos->x;
		$this->y = $pos->y;
		$this->z = $pos->z;

		$radius = $this->width / 2;
		$this->boundingBox->setBounds($pos->x - $radius, $pos->y + $this->ySize, $pos->z - $radius, $pos->x + $radius, $pos->y + $this->height + $this->ySize, $pos->z + $radius);


		if($this->chunk === null or ($this->chunk->getX() !== ($this->x >> 4) and $this->chunk->getZ() !== ($this->z >> 4))){
			if($this->chunk instanceof FullChunk){
				$this->chunk->removeEntity($this);
			}
			$this->level->loadChunk($this->x >> 4, $this->z >> 4);
			$this->chunk = $this->level->getChunk($this->x >> 4, $this->z >> 4);

			if(!$this->justCreated){
				$newChunk = $this->level->getUsingChunk($this->x >> 4, $this->z >> 4);
				foreach($this->hasSpawned as $player){
					if(!isset($newChunk[$player->getID()])){
						$this->despawnFrom($player);
					}else{
						unset($newChunk[$player->getID()]);
					}
				}
				foreach($newChunk as $player){
					$this->spawnTo($player);
				}
			}

			$this->chunk->addEntity($this);
		}

		return true;
	}

	public function getMotion(){
		return new Vector3($this->motionX, $this->motionY, $this->motionZ);
	}

	public function setMotion(Vector3 $motion){
		if(!$this->justCreated){
			$this->server->getPluginManager()->callEvent($ev = new EntityMotionEvent($this, $motion));
			if($ev->isCancelled()){
				return false;
			}
		}

		$this->motionX = $motion->x;
		$this->motionY = $motion->y;
		$this->motionZ = $motion->z;

		if(!$this->justCreated){
			if($this instanceof Player){
				$pk = new SetEntityMotionPacket;
				$pk->entities = [
					[0, $this->motionX, $this->motionY, $this->motionZ]
				];
				$this->dataPacket($pk);
			}
			$this->updateMovement();
		}

		return true;
	}

	public function isOnGround(){
		return $this->onGround === true;
	}

	public function kill(){
		if($this->dead){
			return;
		}
		$this->dead = true;
		$this->setHealth(0);
		$this->scheduleUpdate();
	}

	/**
	 * @param Vector3|Position|Location $pos
	 * @param float                     $yaw
	 * @param float                     $pitch
	 *
	 * @return bool
	 */
	public function teleport(Vector3 $pos, $yaw = null, $pitch = null){
		if($pos instanceof Location){
			$yaw = $pos->yaw;
			$pitch = $pos->pitch;
		}
		$from = Position::fromObject($this, $this->level);
		$to = Position::fromObject($pos, $pos instanceof Position ? $pos->getLevel() : $this->level);
		$this->server->getPluginManager()->callEvent($ev = new EntityTeleportEvent($this, $from, $to));
		if($ev->isCancelled()){
			return false;
		}
		$this->ySize = 0;
		$pos = $ev->getTo();

		$this->setMotion(new Vector3(0, 0, 0));
		if($this->setPositionAndRotation($pos, $yaw === null ? $this->yaw : $yaw, $pitch === null ? $this->pitch : $pitch, true) !== false){
			$this->fallDistance = 0;
			$this->onGround = true;

			return true;
		}

		return false;
	}

	public function getId(){
		return $this->id;
	}

	public function spawnToAll(){
		foreach($this->level->getUsingChunk($this->x >> 4, $this->z >> 4) as $player){
			if(isset($player->id) and $player->spawned === true){
				$this->spawnTo($player);
			}
		}
	}

	public function despawnFromAll(){
		foreach($this->hasSpawned as $player){
			$this->despawnFrom($player);
		}
	}

	public function close(){
		if($this->closed === false){
			$this->server->getPluginManager()->callEvent(new EntityDespawnEvent($this));
			$this->closed = true;
			unset($this->level->updateEntities[$this->id]);
			if($this->chunk instanceof FullChunk){
				$this->chunk->removeEntity($this);
			}
			if(($level = $this->level) instanceof Level){
				$level->removeEntity($this);
			}
			$this->despawnFromAll();
		}
	}

	abstract public function getData();

	public function __destruct(){
		$this->close();
	}

	public function setMetadata($metadataKey, MetadataValue $metadataValue){
		$this->server->getEntityMetadata()->setMetadata($this, $metadataKey, $metadataValue);
	}

	public function getMetadata($metadataKey){
		return $this->server->getEntityMetadata()->getMetadata($this, $metadataKey);
	}

	public function hasMetadata($metadataKey){
		return $this->server->getEntityMetadata()->hasMetadata($this, $metadataKey);
	}

	public function removeMetadata($metadataKey, Plugin $plugin){
		$this->server->getEntityMetadata()->removeMetadata($this, $metadataKey, $plugin);
	}

	public function __toString(){
		return (new \ReflectionClass($this))->getShortName() . "(" . $this->getID() . ")";
	}

}
