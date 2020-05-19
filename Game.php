<?php

namespace Codingame;
/**
 * Grab the pellets as fast as you can!
 **/

const MAX_DEPTH = 18;
const DEBUG = false;
const DEBUG_MAP = false;

$game = IO::initialize();
// game loop
while (TRUE) {
    $game = IO::readRound($game);
    echo($game->play() . PHP_EOL); // MOVE <pacId> <x> <y>
}

class IO
{
    public static function initialize()
    {
        $height = null;
        fscanf(STDIN, "%d %d", $width, $height);

        $grid = new Grid($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $row = stream_get_line(STDIN, $width + 1, "\n");// one line of the grid: space " " is floor, pound "#" is wall
            foreach (str_split($row) as $x => $type) {
                if ($type === ' ') {
                    $grid->addNode(new Node($x, $y));
                }
            }
        }

        $grid->setUp();

        return new Game($grid);
    }

    public static function readRound(Game $game)
    {
        $game->resetRound();
        $opponentScore = null;
        fscanf(STDIN, "%d %d", $myScore, $opponentScore);
        $game->setMyScore($myScore);
        $game->setOpponentScore($opponentScore);

        fscanf(STDIN, "%d", $visiblePacCount);
        for ($i = 0; $i < $visiblePacCount; $i++) {
            $mine = $x = $y = $typeId = $speedTurnsLeft = $abilityCooldown = null;
            fscanf(STDIN, "%d %d %d %d %s %d %d", $pacId, $mine, $x, $y, $typeId, $speedTurnsLeft, $abilityCooldown);
            $game->updatePac($pacId, $mine, $x, $y, $typeId, $speedTurnsLeft, $abilityCooldown);
            if ($game->getRound() == 0) {
                Grid::$availableNodes--;
            }
        }

        // $visiblePelletCount: all pellets in sight
        fscanf(STDIN, "%d", $visiblePelletCount);
        for ($i = 0; $i < $visiblePelletCount; $i++) {
            $x = $y = $value = null;
            fscanf(STDIN, "%d %d %d", $x, $y, $value);
            $game->addVisisblePellet($x, $y);
            $game->getGrid()->updateNode($x, $y, $value);
        }

        $game->initRound();

        return $game;
    }
}

class Game
{
    /**
     * @var Player;
     */
    public $player;
    public $opponent;
    public $grid;
    private $round = 0;
    private $tmpTarget = [];
    private $focused = [];
    private $myScore;
    private $opponentScore;
    private $visiblePellets;
    /**
     * @var Node[]
     */
    private $bigPellets;
    /**
     * @var Node[]
     */
    private $smallPellets;

    /**
     * Game constructor.
     */
    public function __construct(Grid $grid)
    {
        $this->grid = $grid;
        $grid->setGame($this);
        $this->player = new Player();
        $this->opponent = new Player();
    }

    /**
     * @return int
     */
    public function getRound(): int
    {
        return $this->round;
    }

    public function getPlayer()
    {
        return $this->player;
    }

    public function getOpponent()
    {
        return $this->opponent;
    }

    /**
     * @return Grid
     */
    public function getGrid(): Grid
    {
        return $this->grid;
    }

    public function getMyScore()
    {
        return $this->myScore;
    }

    public function setMyScore($myScore)
    {
        $this->myScore = $myScore;
    }

    public function resetRound()
    {
        $this->visiblePellets = [];
        $this->grid->resetSecureNodes();
        foreach ($this->getOpponent()->getPacs() as $pac) {
            if ($pac->isVisible()) {
                $pac->hide($this->round);
            }
        }
    }

    public function initRound()
    {
        if ($this->round == 0) {
            // here its the first round need to create invisible ennemy pacs
            $this->createInvisiblePacs();
        }
        $this->focused = [];
        $this->tmpTarget = [];
        $this->round++;

        //reset focus pac
        // here check previous position of my pacs to watch if they are blocked
        $this->resetMyPacs();
        $this->trackingPacs();
        $this->checkFights();

        $this->spreadPellets();
        $this->trackingNodes();

        // here we are looking for ennemy pac who are chasing me !
        foreach ($this->getPlayer()->getPacs() as $pac) {
            $pac->setChassed(false);
            foreach ($this->getOpponent()->getPacs() as $ennemyPac) {
                if (in_array($ennemyPac->getPosition(), $pac->getPosition()->getNeighbors())) {
                    $pac->setChassed(true);
                }
            }
        }

    }

    private function checkFights()
    {
        foreach ($this->getPlayer()->getAllPacs() as $myPac) {
            if ($myPac->isDead() && $myPac->getDeadAt() == $this->round - 1) {
                $possiblePacs = [];
                foreach ($this->getOpponent()->getInvisiblePacs() as $ennemyPac) {
                    if (in_array($myPac->getPosition(), $ennemyPac->getNodes())) {
                        $possiblePacs[] = $ennemyPac;
                    }
                }
                if (count($possiblePacs) === 1) {
                    $ep = $possiblePacs[0];
                    dump('set fight ' . $ep . ' position : ' . $myPac->getPosition());
                    $ep->setPosition($myPac->getPosition());
                    $ep->setNodes($myPac->getPosition()->getNeighbors());
                    $ep->visible();
                }
            }
        }
    }

    public function spreadPellets()
    {
        $this->bigPellets = [];
        $this->smallPellets = [];
        $undiscoverd = [];
        foreach ($this->getGrid()->getNodeList() as $node) {
            if ($node->getValue() == 10) {
                $node->resetEnnemyDist();
                $this->bigPellets[] = $node;
            } elseif ($node->getValue() > 0) {
                $this->smallPellets[] = $node;
            } elseif (!$node->isDiscovered()) {
                $undiscoverd[] = $node;
            }
        }
    }

    public function addVisisblePellet($x, $y)
    {
        $this->visiblePellets[] = $this->getGrid()->getPoint($x, $y);
    }

    public function createInvisiblePacs()
    {
        $grid = $this->getGrid();
        foreach ($this->getPlayer()->getAllPacs() as $pac) {
            $opponentPac = $this->getOpponent()->getPac($pac->getId());
            if ($opponentPac) {
                continue;
            }
            // here no enemy pac create it;
            $myPosition = $pac->getPosition();
            $x = $grid->getWidth() - 1 - $myPosition->getX();
            $this->updatePac($pac->getId(), -1, $x, $myPosition->getY(), $pac->getTypeId(), $pac->getSpeedTurnsLeft(), $pac->getAbilityCooldown());
            Grid::$availableNodes--;
        }
    }

    /**
     * @return mixed
     */
    public function getOpponentScore()
    {
        return $this->opponentScore;
    }

    /**
     * @param mixed $opponentScore
     */
    public function setOpponentScore($opponentScore)
    {
        $this->opponentScore = $opponentScore;
    }

    public function updatePac($pacId, $mine, $x, $y, $typeId, $speedTurnsLeft, $abilityCooldown)
    {
        $player = ($mine === 1) ? $this->getPlayer() : $this->getOpponent();
        $node = $this->getGrid()->updateNode($x, $y, 0);
        $player->updatePac(($mine === 1), $pacId, $node, $typeId, $speedTurnsLeft, $abilityCooldown, $this->round);
    }

    public function getPacPositions(Pac $currentPac)
    {
        $positions = [];

        foreach ($this->getOpponent()->getPacs() as $pac) {
            $positions[] = $pac->getPosition();
            if (!$currentPac->beat($pac) && !$currentPac->draw($pac)) {
                $dist = 1;
                if ($pac->getSpeedTurnsLeft() > 0) {
                    $dist = 2;
                }
                $positions = array_merge($positions, $pac->getPosition()->getNeighborsByDist($dist));
            }

        }

        foreach ($this->getPlayer()->getPacs() as $pac) {
            if ($pac !== $currentPac) {
                if ($pac->getNextPosition()) {
                    $positions = array_merge($positions, $pac->getPosition()->getPathsTo($pac->getNextPosition()));
                } else {
                    $positions[] = $pac->getPosition();
                }
            }
        }

        return $positions;
    }

    public function getAllOtherPacPositions(Pac $currentPac, $withNeighbors = true)
    {
        $pacs = [];
        foreach ($this->getOpponent()->getAlivePacs() as $pac) {
            if (!$currentPac->beat($pac) && !$currentPac->draw($pac)) {
                if ($pac->isVisible()) {
                    $pacs[] = $pac->getPosition();
                    if ($withNeighbors) {
                        $pacs = array_merge($pacs, $pac->getPosition()->getNeighbors());
                    }

                } elseif (count($pac->getNodes()) < 4 && $withNeighbors) {
                    foreach ($pac->getNodes() as $n) {
                        $pacs = array_merge($pacs, $n->getNeighbors());
                    }
                }
            }
        }

        foreach ($this->getPlayer()->getPacs() as $pac) {
            if ($pac !== $currentPac) {
                if ($pac->getNextPosition()) {
                    $pacs[] = $pac->getNextPosition();
                }
                if ($withNeighbors) {
                    $pacs[] = $pac->getPosition();;
                    $pacs = array_merge($pacs, $pac->getPosition()->getNeighbors());
                }

            }
        }

        return $pacs;
    }


    public function play()
    {
        if (DEBUG) {
            $this->debug();
        }

        $player = $this->getPlayer();

        if ($this->round < 2) {
            $this->dispatchBigPellets();
        } else {
            foreach ($player->getPacs() as $pac) {
                if ($pac->hasFocus()) {
                    foreach ($pac->getFocus() as $node) {
                        $this->tmpTarget[] = $node;
                    }
                }
            }
        }


        $actions = [];

        foreach ($player->getPacs() as $pac) {
            if ($pac->hasFocus()) {
                if ($pac->hasAbility() && !$pac->isBlocked()) {
                    $actions[] = 'SPEED ' . $pac->getId() . ' FS';
                    continue;
                }
                $path = $pac->getFocus();
                $nextNode = array_shift($path);
                $msg = 'FF';

                if ($pac->getSpeedTurnsLeft() > 0 && count($path) > 0) {
                    $nextNode = array_shift($path);
                }

                $pac->setNextPosition($nextNode);
                if ($path) {
                    $pac->setFocus($path);
                    $this->focused = array_merge($this->focused, $path);
                } else {
                    $pac->resetFocus();
                }

                $actions[] = "MOVE " . $pac->getId() . " " . $nextNode->getX() . " " . $nextNode->getY() . ' ' . $msg;
                continue;
            }

            if ($pac->hasTarget()) {
                if (!$pac->beat($pac->getTarget()) || !$pac->getTarget()->getPosition() || $pac->getTarget()->isDead()) {
                    //reset targe no more available
                    $pac->resetTarget();
                } elseif ($pac->hasAbility()) {
                    $actions[] = 'SPEED ' . $pac->getId() . ' T.T:' . $pac->getTarget()->getId();
                    continue;
                } else {
                    $this->tmpTarget[] = $pac->getTarget()->getPosition();
                    if ($pac->getSpeedTurnsLeft() > 0) {
                        // j'ai un boost je tent d'aller chercher un voisin pour avancer plus vite;
                        foreach ($pac->getTarget()->getPosition()->getNeighbors() as $neighbor) {
                            if ($neighbor !== $pac->getPosition()) {
                                $this->tmpTarget[] = $pac->getPosition();
                                $actions[] = "MOVE " . $pac->getId() . " " . $neighbor->getX() . " " . $neighbor->getY() . ' T.S:' . $pac->getTarget()->getId();
                                break;
                            }
                        }
                        continue;
                    } elseif ($this->isDeadEnd($pac, $pac->getTarget())) {
                        //here check if ennemy is blocked
                        $actions[] = "MOVE " . $pac->getId() . " " . $pac->getTarget()->getPosition()->getX() . " " . $pac->getTarget()->getPosition()->getY() . ' D.E:' . $pac->getTarget()->getId();
                        continue;
                    } else {
                        $pac->resetTarget();
                    }

                }

            }

            // check if the pac is on the same position
            if ($pac->isBlocked() && $pac->hasAbility()) {
                // if i'am block try to switch and beat enemy;
//                dump('here blocked switch ! ');
                //$actions[] = 'SWITCH ' . $pac->getId() . ' ' . $pac->typeToBeat($pac);
                $pac->wait();
                //continue;
            }

            $action = $this->searchDestory($pac);
            if ($pac->isWaiting()) {
                continue;
            }
            if (!$action) {
                $action = $this->findMove($pac);
                if ($pac->hasAbility() && !$pac->isBlocked() && $this->isSafeSpeed($pac)) {
                    $actions[] = 'SPEED ' . $pac->getId() . ' m.m';
                    continue;
                }
            }

            if ($action) {
                $actions[] = $action;
            } else {
                $pac->wait();
            }

        }

        return implode('|', $actions);
    }

    private function isSafeSpeed(Pac $pac)
    {
        foreach ($this->getOpponent()->getPacs() as $ennemyPac) {
            $path = $pac->getPosition()->getPathsTo($ennemyPac->getPosition());
            if (count($path) < 4 && $pac->getPosition()->isDeadEnd()) {
                return false;
            }

            if (count($path) < 3 && $ennemyPac->getSpeedTurnsLeft() > 0) {
                return false;
            }
        }

        return true;

    }

    private function isDeadEnd(Pac $me, Pac $target)
    {
        foreach ($target->getPosition()->getPaths() as $path) {
            if (in_array($me->getPosition(), $path)) {
                continue;
            }
            if (count($path) > $target->getAbilityCooldown()) {
                return false;
            }
        }

        return true;
    }

    public function searchDestory(Pac $pac)
    {
        foreach ($this->getOpponent()->getPacs() as $ennemyPac) {

            $ennemyPacPosition = $ennemyPac->getPosition();
            if (!$ennemyPacPosition) {
                continue;
            }
            $myPosition = $pac->getPosition();
            $path = $myPosition->getPathsTo($ennemyPacPosition);
            $dist = count($path) > 0 ? count($path) : 1000;

            if ($pac->beat($ennemyPac)
                && !$this->isTargeted($ennemyPac)
                && $ennemyPac->isVisible()
                && (($ennemyPac->getSpeedTurnsLeft() == 0 && $dist < 3) || ($ennemyPacPosition->isDeadEnd() && $dist < 4))
                && ($pac->hasAbility() || $pac->getSpeedTurnsLeft() > 0)) {

                if ($dist < 2) {
                    // here mark pac as targeted .
                    if ($ennemyPac->hasAbility()) {
                        //here we need to temp for seeing
                        $pac->wait();
                        return;
                    }
                    $pac->setTarget($ennemyPac);
                    $this->tmpTarget[] = $ennemyPacPosition;
                    if ($pac->getSpeedTurnsLeft() > 0) {
                        // j'ai un boost je tent d'aller chercher un voisin pour avancer plus vite;
                        foreach ($ennemyPacPosition->getNeighbors() as $neighbor) {
                            if ($neighbor !== $myPosition) {
                                return "MOVE " . $pac->getId() . " " . $neighbor->getX() . " " . $neighbor->getY() . ' T.S:' . $ennemyPac->getId();
                            }
                        }
                    }

                    return "MOVE " . $pac->getId() . " " . $ennemyPac->getPosition()->getX() . " " . $ennemyPac->getPosition()->getY() . ' T:' . $ennemyPac->getId();
                } elseif ($ennemyPacPosition->isDeadEnd() && !$ennemyPac->hasAbility()) {
                    $pac->setTarget($ennemyPac);
                    $this->tmpTarget[] = $ennemyPacPosition;
                    return "MOVE " . $pac->getId() . " " . $ennemyPac->getPosition()->getX() . " " . $ennemyPac->getPosition()->getY() . ' T:' . $ennemyPac->getId();
                }

            } elseif ($dist < 2 && $pac->hasAbility()

                && !$pac->draw($ennemyPac)
                && $ennemyPac->isVisible()) {
                if ($ennemyPac->hasAbility() && $ennemyPac->draw($pac)) {
                    //here we need to temp for seeing
                    $pac->wait();
                    return;
                }
                $pac->setTarget($ennemyPac);
                return 'SWITCH ' . $pac->getId() . ' ' . $pac->typeToBeat($ennemyPac) . ' AE2' . $ennemyPac->getId();
            } elseif ($dist < 4 && $pac->hasAbility() && $ennemyPac->isVisible()) {
                //return 'SWITCH ' . $pac->getId() . ' ' . $pac->typeToBeat($ennemyPac).' AE4'.$ennemyPac->getId();
            }
        }


        return false;
    }

    public function findMove(Pac $pac)
    {

        list($target, $nextNode, $bestPath, $msg) = $this->moveBigPellet($pac);

        if (!$nextNode) {
            list($target, $nextNode, $bestPath, $msg) = $this->nextNode($pac);
        }

        if (!$nextNode) {
            list($target, $nextNode, $msg) = $this->p($pac);
        }

        if (!$nextNode) {
            list($target, $nextNode, $msg) = $this->u($pac);
        }

        if ($nextNode) {
            if ($bestPath) {
                $this->focused = array_merge($this->focused, $bestPath);
            }
            $pac->setNextPosition($nextNode);
            $this->tmpTarget[] = $target;
            $this->tmpTarget[] = $nextNode;
            return "MOVE " . $pac->getId() . " " . $nextNode->getX() . " " . $nextNode->getY() . ' ' . $msg;
        }

        dump(' oops no more moves .....' . count($this->getGrid()->getDiscoveredNodeList()) . ' pellets ' . count($this->smallPellets));


        return false;
    }

    public function isClosestPath(Pac $pac, Node $node)
    {
        $path = $pac->pathTo($node);
        if (!$this->isAvailablePacPath($pac, $path) || !$path) {
            return false;
        }
        $dist = count($path);
        $pacs = array_merge($this->getPlayer()->getPacs(), $this->getOpponent()->getAllPacs());
        foreach ($pacs as $p) {
            if ($p !== $pac) {
                $pPath = $p->pathTo($node);
                $pDist = count($pPath);
                if ($pDist < $dist) {
                    return false;
                }
            }
        }

        return $path;
    }

    public function getClosestEnnemyDistance(Node $node)
    {
        $dist = INF;
        $pacs = $this->getOpponent()->getAlivePacs();
        foreach ($pacs as $p) {
            $pPath = $p->pathTo($node);
            $pDist = count($pPath);
            if ($pDist < $dist) {
                $dist = $pDist;
            }
        }

        return $dist;
    }

    public function isAvailablePacPath(Pac $pac, array $path)
    {
        foreach ($this->getAllOtherPacPositions($pac) as $position) {
            if (in_array($position, $path)) {
                return false;
            }
        }

        return true;
    }

    public function isNotTargeted(Node $node)
    {
        return !in_array($node, $this->tmpTarget);
    }

    public function isTargeted(Pac $ennemyPac)
    {
        foreach ($this->getPlayer()->getPacs() as $pac) {
            if ($pac->getTarget() === $ennemyPac) {
                return true;
            }
        }

        return false;
    }

    public function debug()
    {
//        dump(' discovered nodes   : ' . count($this->getGrid()->getDiscoveredNodeList()));
//        dump(' undiscovered nodes : ' . count($this->getGrid()->getUndiscoveredNodeList()));
//        dump(' available pellets  : ' . Grid::$availableNodes);
//        dump(' possible pellets   : ' . count($this->getGrid()->getPelletNodeList()));
//        dump(' my score           : ' . $this->getMyScore());
//        dump(' opponent score     : ' . $this->getOpponentScore());
//        dump(' lost pellets       : ' . (Grid::$availableNodes - $this->getOpponentScore() - $this->getMyScore() - count($this->getGrid()->getPelletNodeList())) );
//        dump(' possible nodes ... : '.count($this->getOpponentPossibleNodes()));
        if (DEBUG_MAP) {
            $this->getGrid()->dump();
        }

        dump('=== OPPONENT PACS ===');
        foreach ($this->getOpponent()->getAllPacs() as $pac) {
            if ($pac->isDead()) {
                dump('   PAC :' . $pac . ' DEAD');
            } else {
                dump('   PAC :' . $pac . ' ability : ' . (int)$pac->getAbilityCooldown() . ' speed turn left : ' . (int)$pac->getSpeedTurnsLeft() . ' vis: ' . (int)$pac->isVisible() . ' nodes : ' . implode('|', $pac->getNodes()));
            }
        }
        dump('=== MY PACS ===');
        foreach ($this->getPlayer()->getAllPacs() as $pac) {
            if ($pac->isDead()) {
                dump('   PAC :' . $pac . ' DEAD');
            } else {
                dump('   PAC :' . $pac . ' vis: ' . (int)$pac->isVisible() . ' nodes : ' . implode('|', $pac->getNodes()));
            }
        }
    }

    private function trackingNodes()
    {
        //treat super pellets
        $bigPellets = [];
        foreach ($this->bigPellets as $pellet) {
            if (!in_array($pellet, $this->visiblePellets)) {
                $pellet->setValue(0);
                $pellet->visit();
                $possibles = [];
                foreach ($this->getOpponent()->getInvisiblePacs() as $pac) {
                    $totalRounds = ($this->round - $pac->getLostAt());
                    $path = $pac->getPosition()->getPathsTo($pellet);
                    if (count($path) < ($totalRounds * 2)) {
                        $possibles[] = ['pac' => $pac, 'path' => $path];
                    }
                }
                if (count($possibles) === 1) {
                    $pac = $possibles[0]['pac'];
                    $path = $possibles[0]['path'];
                    // here reset all node on his path ....
                    foreach ($path as $node) {
                        $node->setValue(0);
                        $pellet->visit();
                    }
                    // here we got the only one possible !!!
                    $pac->setPosition($pellet);
                    $pac->setNodes([$pellet]);
                    $pac->visible();
                }
            } else {
                $bigPellets[] = $pellet;
            }
        }
        $this->bigPellets = $bigPellets;

        // here set discoverd node on my line pac
        foreach ($this->getPlayer()->getPacs() as $pac) {
            $pos = $pac->getPosition();
            $pos->discovered();
            foreach ($pos->getVisibleNodes() as $vn) {
                $vn->discovered();
                if (!in_array($vn, $this->visiblePellets) && $vn->getValue() > 0) {
                    $vn->setValue(0);
                }
            }
        }
    }

    private function trackingPacs()
    {
        $visibles = $this->getAllVisibleNodes();
        // here set discoverd node on my line pac
        foreach ($this->getOpponent()->getAllPacs() as $pac) {
            if ($pac->isVisible() && $pac->getLostAt() !== ($this->round - 1)) {
                $currentPosition = $pac->getPosition();
                $totalRounds = ($this->round - $pac->getLostAt());
                $possibles = [];
                foreach ($pac->getNodes() as $possibleNode) {
                    $path = $currentPosition->getPathsTo($possibleNode);
                    $diff = array_diff($visibles, $path);
                    if (count($diff) !== count($visibles)) {
                        continue;
                    }
                    if (count($path) < ($totalRounds * 2)) {
                        $possibles[] = $path;
                    }
                }
                if (count($possibles) === 1) {
                    $path = $possibles[0];
                    foreach ($path as $node) {
                        $node->setValue(0);
                    }
                }
                dump($pac . ' update node ' . implode('|', $pac->getNodes()));
                $pac->setNodes([$pac->getPosition()]);
            } // update pac nodes on visible ....
            elseif ($pac->isVisible()) {
                $pac->setNodes([$pac->getPosition()]);
            }
            if (!$pac->isVisible()) {
                $nodes = [];
                dump($pac . ' invisible ' . implode('|', $pac->getNodes()));
                if (!$pac->getNodes()) {
                    $pac->setNodes([$pac->getPosition()]);
                }
//
//                if($pac->hasAbility()){
//                    $pac->setAbilityCooldown(10);
//                    $dist = 0;
//                }else{
//                    $ability = $pac->getAbilityCooldown();
//                    $dist = $ability > 3 ? 2 : 1;
//                    $pac->setAbilityCooldown($ability--);
//                }

                $dist = 2;
                foreach ($pac->getNodes() as $node) {
//                    if (!in_array($node, $visibles)) {
//                        if (!in_array($node, $nodes)) {
//                            $node->addSecure();
//                            $nodes[] = $node;
//                        }

                    foreach ($node->getNeighborsByDist($dist) as $neighbor) {
                        if (!in_array($neighbor, $nodes)
                            && !in_array($neighbor, $visibles)
                            //&& $neighbor->getValue() > 0
                        ) {
                            //$neighbor->setValue(0);
                            $neighbor->addSecure();
                            $nodes[] = $neighbor;
                        }
                    }
//                    }

                }
                dump('   ..... update .... ' . implode('|', $pac->getNodes()));
                $pac->setNodes($nodes);
                if (count($nodes) == 1) {
                    $path = $pac->getPosition()->getPathsTo($nodes[0]);
                    foreach ($path as $node) {
                        $node->setValue(0);
                    }
                    dump('set ' . $pac . ' position : ' . $nodes[0]);
                    $pac->setPosition($nodes[0]);
                    $pac->visible();
                }
            }
        }
    }

    private function getAllVisibleNodes()
    {
        $visibles = [];
        foreach ($this->getPlayer()->getPacs() as $pac) {
            $visibles = array_merge($visibles, $pac->getPosition()->getVisibleNodes(), [$pac->getPosition()]);
        }

        return $visibles;
    }

    private function dispatchBigPellets()
    {
        foreach ($this->bigPellets as $bigPellet) {
            $dist = $this->getClosestEnnemyDistance($bigPellet);
            $bigPellet->setEnnemyDist($dist);

        }
        $maxPellet = 0;
        $shortestDist = INF;
        $bestPath = $bestPac = false;
        foreach ($this->bigPellets as $bigPellet) {
            foreach ($this->getPlayer()->getPacs() as $pac) {
                $path = $pac->pathTo($bigPellet);
                $dist = count($path);
                $count = 0;
                foreach ($path as $node) {
                    if ($node->getValue() == 10) {
                        $count++;
                    }
                }

                if ($bigPellet->getEnnemyDist() > count($path)) {
                    if ($count >= $maxPellet) {
                        if ($count == $maxPellet && $shortestDist > $dist) {
                            $maxPellet = $count;
                            $shortestDist = $dist;
                            $bestPac = $pac;
                            $bestPath = $path;
                        } elseif ($count > $maxPellet) {
                            $maxPellet = $count;
                            $shortestDist = $dist;
                            $bestPac = $pac;
                            $bestPath = $path;
                        }
                    }
                }
            }

        }

        if ($bestPath) {
            foreach ($bestPath as $node) {
                $this->tmpTarget[] = $node;
            }
            $bestPac->setFocus($bestPath);
        }


    }

    /**
     * @param Node $pacPosition
     * @param Pac $pac
     * @return array
     */
    private function moveBigPellet(Pac $pac): array
    {
        $msg = 'P';
        $nextNode = false;
        $bestPath = false;
        $target = false;
        $min = INF;

        // test finding best path
        foreach ($this->bigPellets as $pellet) {
            if (in_array($pellet, $this->tmpTarget)) {
                continue;
            }
            $path = $pac->pathTo($pellet);
            $count = 0;
            foreach ($path as $node) {
                if ($node->getValue() == 10) {
                    $count++;
                }
            }

            if (false !== $path = $this->isClosestPath($pac, $pellet)) {
                $dist = count($path);
                if ($dist < $min) {
                    $min = $dist;
                    $target = $pellet;
                    $nextNode = $path[0];
                    $msg .= $pellet;
                    if ($pac->getSpeedTurnsLeft() > 0 && count($path) > 1) {
                        $nextNode = $path[1];
                    } elseif ($pac->getSpeedTurnsLeft() > 0) {
                        $max = -1;
                        foreach ($nextNode->getNeighbors() as $neighbor) {
                            if ($neighbor->getValue() > $max && $neighbor !== $pac->getPosition()) {
                                $max = $neighbor->getValue();
                                $nextNode = $neighbor;
                                $bestPath = $path;
                            }
                        }
                    }
                }

            }
        }

        return [$target, $nextNode, $bestPath, $msg];

    }

    private function nextNode(Pac $pac)
    {
        $msg = 'b+';
        $target = false;
        $nextNode = false;
        $bestScore = 0;
        $bestPath = false;
        $paths = Grid::getPaths($pac->getPosition());
        foreach ($paths as $path) {
            $score = $this->getAverageScore($pac, $path);//+ ($pac->getId());
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = $path;
            }
        }

        if ($bestPath) {
            $nextNode = $bestPath[0];
            $target = $nextNode;
            if ($pac->getSpeedTurnsLeft() > 0 && count($bestPath) > 1) {
                $nextNode = $bestPath[1];
            }
        }

        return array($target, $nextNode, $bestPath, $msg);
    }

    private function getAverageScore(Pac $pac, $path)
    {
        $score = 0;
        $blocked = $this->getPacPositions($pac);
        $blocked = array_merge($blocked, $this->tmpTarget);
        $length = 0;
        /**
         * @var Node $entry
         */
        foreach ($path as $i => $entry) {
            $length++;
            if (in_array($entry, $blocked)) {
                break;
            }
            $score += $entry->getPathScore();
            if ($entry->getPathScore() > 0 && !in_array($entry, $this->focused) && $entry->isSecure()) {
                $score += (MAX_DEPTH / $length);
            } elseif ($entry->isSecure()) {
                //$score -= 0.5;
            }

        }

        if ($length === 0) {
            return 0;
        }

        return $score;
    }

    /**
     * @param Node $pacPosition
     * @param array $blocked
     * @param Pac $pac
     * @return array
     */
    private function p(Pac $pac): array
    {
        $dist = INF;
        $blocked = $this->getPacPositions($pac);
        $blocked = array_merge($blocked, $this->tmpTarget);
        $nextNode = false;
        $target = false;
        $msg = 'p';
        $pacPosition = $pac->getPosition();

        foreach ($this->visiblePellets as $pellet) {

            $path = $pacPosition->getPathsTo($pellet);
            $manhattan = count($path);
            $score = $this->getAverageScore($pac, $path);//+ ($pac->getId());
            if ($score == 0) {
                continue;
            }

            if ($dist > $manhattan && $this->isNotTargeted($pellet)) {
                if ($path && !in_array($path[0], $blocked) && (!$pac->isChassed() || $pac->isChassed() && count($path) > $pac->getAbilityCooldown())) {
                    $dist = $manhattan;
                    if ($pac->getSpeedTurnsLeft() > 0) {
                        if (count($path) > 1) {
                            array_shift($path);
                        } else {
                            foreach ($path[0]->getNeighbors() as $neighbor) {
                                if ($neighbor !== $pacPosition) {
                                    $nextNode = $neighbor;
                                    $target = $pellet;
                                    continue 2;
                                }
                            }
                        }

                    }
                    $nextNode = array_shift($path);
                    $target = $pellet;

                }
            }
        }
        if ($target) {
            $msg .= $target;
        }

        return array($target, $nextNode, $msg);
    }

    /**
     * @param Node $pacPosition
     * @param array $blocked
     * @param Pac $pac
     * @return array
     */
    private function u(Pac $pac): array
    {
        //last chance
        $max = 0;
        $blocked = $this->getPacPositions($pac);
        $nextNode = false;
        $target = false;
        $msg = 'u';
        $pacPosition = $pac->getPosition();

        foreach ($this->getGrid()->getUndiscoveredNodeList() as $node) {
            $n = Grid::maximiseUndiscovered($node, []);
            if ($n > $max && $this->isNotTargeted($node)) {
                $path = $pacPosition->getPathsTo($node);
                if ($path && !in_array($path[0], $blocked)) {
                    $max = $n;
                    if ($pac->getSpeedTurnsLeft() > 0 && count($path) > 1) {
                        array_shift($path);
                    }
                    $nextNode = array_shift($path);
                    $target = $node;
                }
            }
        }

        if ($target) {
            $msg .= $target;
        }

        return array($target, $nextNode, $msg);
    }

    private function resetMyPacs()
    {
        foreach ($this->getPlayer()->getPacs() as $pac) {
            $pac->setBlocked($this->round > 1 && $pac->getNextPosition() !== $pac->getPosition() && !$pac->isWaiting());
            $pac->move();
            $pac->resetNextPosition();
        }
    }
}

class Player
{
    /**
     * @var Pac[]
     */
    private $pacs = [];

    public function addPac(Pac $pac)
    {
        $this->pacs[$pac->getId()] = $pac;
    }

    /**
     * @return Pac
     */
    public function getPac($id)
    {
        return $this->pacs[$id] ?? null;
    }

    /**
     * @return Pac[]
     */
    public function getPacs()
    {
        return array_filter($this->pacs, function (Pac $pac) {
            return !$pac->isDead() && $pac->isVisible();
        });
    }

    /**
     * @return Pac[]
     */
    public function getAllPacs()
    {
        return $this->pacs;
    }

    /**
     * @return Pac[]
     */
    public function getAlivePacs()
    {
        return array_filter($this->pacs, function (Pac $pac) {
            return !$pac->isDead();
        });
    }

    /**
     * @return Pac[]
     */
    public function getInvisiblePacs()
    {
        return array_filter($this->pacs, function (Pac $pac) {
            return !$pac->isDead() && !$pac->isVisible();
        });
    }

    /**
     * @return Pac|bool
     */
    public function getPacFromPosition(Node $node)
    {
        foreach ($this->getPacs() as $pac) {
            if ($node === $pac->getPosition()) {
                return $pac;
            }
        }

        return false;
    }

    /**
     * @return Pac
     */
    public function getPacFromInvisiblePosition(Node $node)
    {
        foreach ($this->getInvisiblePacs() as $pac) {
            if ($node === $pac->getPosition()) {
                return $pac;
            }
        }

        return false;
    }

    public function updatePac($mine, $pacId, Node $node, $typeId, $speedTurnsLeft, $abilityCooldown, $round)
    {
        if (null === $pac = $this->getPac($pacId)) {
            $pac = new Pac($pacId, $mine);
            $this->addPac($pac);

        }
        if (!$pac->isVisible() && $pac->getNodes()) {
            $pelletToRemove = $pac->getPosition()->getPathsTo($node);
            foreach ($pelletToRemove as $next) {
                if (in_array($next, $pac->getNodes())) {
                    $next->setValue(0);
                    //$next->discovered();
                }
            }

        }
        $pac->visible();
        dump('set update ' . $pac . ' position : ' . $node);
        $pac->setPosition($node);
        //$pac->setNodes([$node]);
        $pac->setTypeId($typeId);
        if ($typeId === Pac::DEAD) {
            $pac->setDeadAt($round);
        }
        $pac->setSpeedTurnsLeft($speedTurnsLeft);
        $pac->setAbilityCooldown($abilityCooldown);
    }
}

class Pac
{
    const ROCK = 'ROCK';
    const PAPER = 'PAPER';
    const SCISSORS = 'SCISSORS';
    const DEAD = 'DEAD';

    private $id;
    /**
     * @var Node
     */
    private $position;
    /**
     * @var Node
     */
    private $nextPosition;

    private $previousPosition;
    private $blocked = false;
    private $typeId, $speedTurnsLeft, $abilityCooldown;
    private $mine;
    private $visible = false;
    private $target = null;
    private $wait = false;
    private $nodes = [];
    private $chassed = false;
    private $lostAt = 0;
    private $deadAt = 0;
    private $paths = [];

    /**
     * @var Node
     */
    private $focus = null;

    /**
     * Pac constructor.
     * @param $id
     * @param $position
     */
    public function __construct($id, $mine)
    {
        $this->id = $id;
        $this->mine = $mine;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    public function getFocus()
    {
        return $this->focus;
    }

    public function setFocus($focus)
    {
        $this->focus = $focus;
    }

    public function resetFocus()
    {
        $this->focus = null;
    }

    public function hasFocus()
    {
        return null !== $this->focus;
    }

    public function setNodes(array $nodes)
    {
        $this->nodes = $nodes;
    }

    /**
     * @return Node[];
     */
    public function getNodes()
    {
        return $this->nodes;
    }

    /**
     * @return Node
     */
    public function getPosition()
    {
        return $this->position;
    }

    /**
     * @param Node $position
     */
    public function setPosition(Node $position)
    {
        $this->position = $position;
    }

    /**
     * @return Node
     */
    public function getNextPosition()
    {
        return $this->nextPosition;
    }

    /**
     * @param Node $nextPosition
     */
    public function setNextPosition(Node $nextPosition)
    {
        $this->nextPosition = $nextPosition;
    }

    public function resetNextPosition()
    {
        $this->nextPosition = null;
    }


    /**
     * @return bool
     */
    public function isChassed(): bool
    {
        return $this->chassed;
    }

    /**
     * @param bool $chassed
     */
    public function setChassed(bool $chassed)
    {
        $this->chassed = $chassed;
    }

    /**
     * @return bool
     */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * @param bool $blocked
     */
    public function setBlocked(bool $blocked)
    {
        $this->blocked = $blocked;
    }

    /**
     * @return null|Pac
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * @param null $target
     */
    public function setTarget(Pac $target)
    {
        $this->target = $target;
    }

    /**
     * @param bool
     */
    public function hasTarget()
    {
        return $this->target !== null;
    }

    public function resetTarget()
    {
        $this->target = null;
    }


    /**
     * @return bool
     */
    public function isDead()
    {
        return $this->typeId === self::DEAD;
    }


    /**
     * @return bool
     */
    public function isWaiting()
    {
        return $this->wait;
    }

    public function wait()
    {
        $this->wait = true;
    }

    public function move()
    {
        $this->wait = false;
    }

    /**
     * @return bool
     */
    public function isVisible()
    {
        return $this->visible;
    }

    public function hide($round)
    {
        $this->lostAt = $round;
        $this->visible = false;
    }

    public function visible()
    {
        $this->visible = true;
    }

    /**
     * @return int
     */
    public function getLostAt(): int
    {
        return $this->lostAt;
    }

    /**
     * @return mixed
     */
    public function getTypeId()
    {
        return $this->typeId;
    }

    /**
     * @param mixed $typeId
     */
    public function setTypeId($typeId)
    {
        $this->typeId = $typeId;
    }

    public function setDeadAt($round)
    {
        $this->deadAt = $round;
    }

    /**
     * @return int
     */
    public function getDeadAt(): int
    {
        return $this->deadAt;
    }

    /**
     * @return mixed
     */
    public function getSpeedTurnsLeft()
    {
        return $this->speedTurnsLeft;
    }

    /**
     * @param mixed $speedTurnsLeft
     */
    public function setSpeedTurnsLeft($speedTurnsLeft)
    {
        $this->speedTurnsLeft = $speedTurnsLeft;
    }

    /**
     * @return mixed
     */
    public function getAbilityCooldown()
    {
        return $this->abilityCooldown;
    }

    public function hasAbility()
    {
        return $this->abilityCooldown == 0;
    }

    /**
     * @param mixed $abilityCooldown
     */
    public function setAbilityCooldown($abilityCooldown)
    {
        $this->abilityCooldown = $abilityCooldown;
    }

    public function beat(Pac $pac)
    {
        $me = $this->getTypeId();
        $him = $pac->getTypeId();
        if (
            ($me === self::PAPER && $him === self::ROCK)
            || ($me === self::ROCK && $him === self::SCISSORS)
            || ($me === self::SCISSORS && $him === self::PAPER)
        ) {
            return true;
        }

        return false;
    }

    public function draw(Pac $pac)
    {
        return $this->getTypeId() === $pac->getTypeId();
    }

    public function isMine()
    {
        return $this->mine;
    }

    public function typeToBeat(Pac $pac)
    {
        $type = $pac->getTypeId();
        if ($type == self::ROCK) {
            return self::PAPER;
        }
        if ($type == self::SCISSORS) {
            return self::ROCK;
        }
        if ($type == self::PAPER) {
            return self::SCISSORS;
        }

        return false;
    }

    public function pathTo(Node $target)
    {
        if ($this->getPosition()) {
            return $this->getPosition()->getPathsTo($target);
        }

        return array_fill(0, Grid::$availableNodes, "xxxx");
    }

    public function __toString()
    {
        $pos = 'XXX';
        if ($this->getPosition()) {
            $pos = ' (' . $this->getPosition()->getX() . '-' . $this->getPosition()->getY() . ')';
        }

        return ($this->isMine() ? 'mine' : 'theirs') . ' ' . $this->getId() . ' ' . $this->getTypeId() . ' ' . $pos;
    }


}

class Node
{
    private $x = 0;
    private $y = 0;
    private $visited = false;
    private $closed = false;
    private $parent = null;

    private $totalScore = 0;
    private $guessedScore = 0;
    private $score = 0;
    private $value;
    private $name;
    private $discovered = false;
    private $paths = [];
    private $visibleNodes = [];
    private $ennemyDist = INF;
    private $secure = 0;

    /**
     * @var Node[]
     */
    private $neighbors = [];

    public function __construct($x, $y, $value = 1)
    {
        $this->x = (int)$x;
        $this->y = (int)$y;
        $this->value = (int)$value;
        $this->name = sprintf('%d-%d', $this->x, $this->y);
    }

    /**
     * @return int
     */
    public function getX()
    {
        return $this->x;
    }

    /**
     * @return int
     */
    public function getY()
    {
        return $this->y;
    }

    /**
     * @return bool
     */
    public function isSecure(): bool
    {
        return $this->secure == 0;
    }

    /**
     * @return int
     */
    public function getSecure()
    {
        return $this->secure;
    }

    public function setSecure(int $secure)
    {
        $this->secure = $secure;
    }

    public function addSecure()
    {
        $this->secure++;
    }

    public function visit()
    {
        $this->visited = true;
    }

    public function addNeighbor(Node $node)
    {
        if (!in_array($node, $this->neighbors)) {
            $this->neighbors[] = $node;
        }
    }

    /**
     * @return Node[]
     */
    public function getNeighbors(): array
    {
        return $this->neighbors;
    }

    public function getNeighborsByDist($dist = 0, $visited = [])
    {
        $visited[] = $this;
        if ($dist == 0) {

            return $visited;
        }
        $dist--;
        foreach ($this->getNeighbors() as $neighbour) {
            if (!in_array($neighbour, $visited)) {
                $visited = $neighbour->getNeighborsByDist($dist, $visited);
            }
        };
        return $visited;
    }

    /**
     * @return mixed
     */
    public function getEnnemyDist()
    {
        return $this->ennemyDist;
    }

    /**
     * @param mixed $ennemyDist
     */
    public function setEnnemyDist($ennemyDist)
    {
        $this->ennemyDist = $ennemyDist;
    }

    public function resetEnnemyDist()
    {
        $this->ennemyDist = INF;
    }

    /**
     * @return Node[][]
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * @param Node[] $paths
     */
    public function setPaths(array $paths)
    {
        $this->paths = $paths;
    }

    public function getPathsTo(Node $node)
    {
        foreach ($this->getPaths() as $path) {
            if (in_array($node, $path)) {
                $index = array_search($node, $path);
                return array_slice($path, 0, $index + 1);
            }
        }

        return [];
    }

    /**
     * @return Node[]
     */
    public function getVisibleNodes(): array
    {
        return $this->visibleNodes;
    }

    /**
     * @param Node[] $visibleNodes
     */
    public function setVisibleNodes(array $visibleNodes)
    {
        $this->visibleNodes = $visibleNodes;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return (int)$this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }

    public function getPathScore()
    {
        return $this->getValue();
    }

    public function __toString()
    {
        return $this->name;//. ' (' . $this->value . ')';
    }

    /**
     * @return bool
     */
    public function isDiscovered()
    {
        return $this->discovered;
    }

    /**
     * @param bool $discovered
     */
    public function discovered()
    {
        $this->discovered = true;
    }

    public function getName()
    {
        return $this->name;
    }

    public function isDeadEnd()
    {
        if (count($this->getNeighbors()) > 2) {
            return false;
        }

        if (count($this->getNeighbors()) === 1) {
            return true;
        }

        $neighborgPaths = [];
        foreach ($this->getPaths() as $path) {
            $neighborgNode = $path[0];
            if (isset($neighborgPaths[$neighborgNode->getName()])) {
                $neighborgPaths[$neighborgNode->getName()]++;
            } else {
                $neighborgPaths[$neighborgNode->getName()] = 1;
            }
        }

        foreach ($neighborgPaths as $n => $l) {
            if ($l === 1) {
                return true;
            }

        }

    }
}

class Grid
{
    static $availableNodes = 0;
    /**
     * @var array
     */
    private $nodes = [];

    /**
     * @var Node[]
     */
    private $nodeList = [];
    private $width;
    private $height;
    private $game;

    /**
     * Grid constructor.
     * @param $width
     * @param $height
     */
    public function __construct($width, $height)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setUp()
    {
        foreach ($this->nodeList as $node) {
            foreach ($this->getNeighbors($node) as $neighbor) {
                $node->addNeighbor($neighbor);
            }
        }
        foreach ($this->nodeList as $node) {
            $paths = Grid::paths($node);
            $node->setPaths($paths);
            $node->setVisibleNodes($this->getVisibleNodes($node));
        }

    }

    public function getVisibleNodes(Node $from)
    {
        $visibleNodes = [];
        $maxX = $this->getWidth() - 1;
        foreach ([-1, 1] as $dir) {
            $x = $from->getX() + $dir;
            $y = $from->getY();
            while (false !== $next = $this->getPoint($x, $y)) {
                if ($next == $from) {
                    break;
                }
                $visibleNodes[] = $next;
                $x += $dir;
                if ($x < 0) {
                    $x = $maxX;
                }
                if ($x > $maxX) {
                    $x = 0;
                }
            }
            $x = $from->getX();
            $y = $from->getY() + $dir;
            while (false !== $next = $this->getPoint($x, $y)) {
                $visibleNodes[] = $next;
                $y += $dir;
            }
        }

        return $visibleNodes;
    }

    public function addNode(Node $node)
    {
        self::$availableNodes++;
        $this->nodeList[] = $node;
        $this->nodes[$node->getX()][$node->getY()] = $node;
    }

    public function updateNode($x, $y, $value)
    {
        $node = $this->getPoint($x, $y);
        $node->setValue($value);
        $node->discovered();

        return $node;
    }

    /**
     * @return array
     */
    public function getNodeList(): array
    {
        return $this->nodeList;
    }

    public function getUndiscoveredNodeList()
    {
        return array_filter($this->nodeList, function (Node $node) {
            return !$node->isDiscovered();
        });
    }

    public function getDiscoveredNodeList()
    {
        return array_filter($this->nodeList, function (Node $node) {
            return $node->isDiscovered();
        });
    }

    public function resetSecureNodes()
    {
        foreach ($this->nodeList as $node) {
            $node->setSecure(0);
        }
    }

    /**
     * @return mixed
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @return mixed
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * @param $y
     * @param $x
     * @return Node | false
     */
    public function getPoint($x, $y)
    {
        return isset($this->nodes[$x][$y]) ? $this->nodes[$x][$y] : false;
    }

    /**
     * @param Node $node
     * @param bool $diagonal
     * @return Node[]
     */
    public function getNeighbors(Node $node)
    {
        $result = [];
        $x = $node->getX();
        $y = $node->getY();
        $maxX = $this->getWidth() - 1;
        $neighbourLocations = [
            [$x - 1, $y],
            [$x + 1, $y],
            [$x, $y - 1],
            [$x, $y + 1]
        ];
        if ($x == 0) {
            $neighbourLocations[] = [$maxX, $y];
        } elseif ($x === $maxX) {
            $neighbourLocations[] = [0, $y];
        }

        foreach ($neighbourLocations as $location) {
            list($x, $y) = $location;
            $node = $this->getPoint($x, $y);
            if ($node) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * @return Game
     */
    public function getGame()
    {
        return $this->game;
    }

    /**
     * @param Game $game
     */
    public function setGame(Game $game)
    {
        $this->game = $game;
    }

    public static function paths(Node $node)
    {
        $queue = new \SplQueue();
        $paths = [];
        # Enqueue the path
        $queue->enqueue([$node]);
        $visited[] = $node;
        $depth = 0;

        while ($queue->count() > 0) {
            $path = $queue->dequeue();

            # Get the last node on the path
            # so we can check if we're at the end
            /**
             * @var Node $node
             */
            $node = $path[sizeof($path) - 1];
            $end = true;
            foreach ($node->getNeighbors() as $neighbour) {

                if (!in_array($neighbour, $visited)) {
                    $visited[] = $neighbour;
                    $end = false;
                    // Build new path appending the neighbour then and enqueue it
                    $new_path = $path;
                    $new_path[] = $neighbour;

                    $queue->enqueue($new_path);
                }
            }
            if ($end) {
                $remove = array_shift($path);
                $paths[] = $path;
                array_unshift($path, $remove);
            }

            $depth++;
        }

        return $paths;
    }

    public static function maximiseUndiscovered(Node $node, $path)
    {
        if ($node->isDiscovered() || in_array($node, $path)) {
            return 0;
        }
        /**
         * Cell[]
         */
        $viewPort = new \SplQueue();
        $viewPort->unshift($node);
        $seen = new \SplObjectStorage();
        $seen->attach($node);

        while (!$viewPort->isEmpty()) {
            /**
             * @var Node $nextCell ;
             */
            $nextCell = $viewPort->pop();

            foreach ($nextCell->getNeighbors() as $neighbor) {
                if (in_array($neighbor, $path) || $seen->contains($neighbor) || $neighbor->isDiscovered()) {
                    continue;
                }
                // $score = Utils::maximise($neighbor, $path);
                $seen->attach($neighbor);
                $viewPort->unshift($neighbor);
            }
        }

        return count($seen);
    }

    public static function getPaths(Node $node, $paths = [], $visited = [])
    {
        $visited[] = $node;
        if (count($visited) > MAX_DEPTH) {
            array_shift($visited);
            $paths[] = $visited;

            return $paths;
        }

        $end = true;
        foreach ($node->getNeighbors() as $neighbour) {
            if (!in_array($neighbour, $visited)) {
                $paths = self::getPaths($neighbour, $paths, $visited);
                $end = false;
            }
        };
        if ($end) {
            array_shift($visited);
            $paths[] = $visited;
        }

        return $paths;
    }

    public function dump()
    {
        $cellWidth = 4;
        $first[] = str_repeat(' ', $cellWidth);
        for ($y = 0; $y < $this->height; $y++) {
            if ($y == 0) {
                for ($c = 0; $c < $this->width; $c++) {
                    $first[] = str_repeat(' ', $cellWidth - strlen($c)) . $c;
                }
                dump(implode('|', $first));
            }
            $line = [str_repeat(' ', $cellWidth - strlen($y)) . $y];
            for ($x = 0; $x < $this->width; $x++) {
                $node = $this->getPoint($x, $y);
                if ($node) {
                    $content = '';

                    if (false !== $pac = $this->getGame()->getOpponent()->getPacFromPosition($node)) {
                        $content .= "E" . $pac->getId();
                    } elseif (false !== $pac = $this->getGame()->getPlayer()->getPacFromPosition($node)) {
                        $content .= "M" . $pac->getId();
                    } elseif (false !== $pac = $this->getGame()->getOpponent()->getPacFromInvisiblePosition($node)) {
                        $content .= "X" . $pac->getId();
                    }

                    if (!$node->isSecure()) {
                        $content .= '*' . $node->getSecure();
                    }

                    $content .= $node->getValue() > 0 ? $node->getValue() > 1 ? 'o' : '.' : '';
                } else {
                    $content = '#';
                }


                $line[] = $content . str_repeat(' ', $cellWidth - strlen($content));
            }
            dump(implode('|', $line));
        }
    }

}

function dump($args)
{
    if (DEBUG) {
        foreach (func_get_args() as $arg) {
            error_log(var_export($arg, true));
        }
    }
}