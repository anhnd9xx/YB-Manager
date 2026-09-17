<?php
declare(strict_types=1);
/**
 * SyncEventQueue - Hang doi event RIENG MOI TARGET (spec muc 18, 20).
 * - Moi target 1 queue: target cham khong block target khac.
 * - Coalesce CHI mouse move: giu event move MOI NHAT, bo move cu chua xu ly.
 * - KHONG BAO GIO coalesce/drop CLICK/KEY/TEXT (isCritical).
 * - Backpressure: maxQueueSize (mac dinh 5000); tran -> drop move cu truoc,
 *   van tran -> drop move moi + dem overflow (ghi warning o dispatcher).
 */
require_once __DIR__ . '/SyncEvent.php';

class SyncEventQueue
{
    private SplQueue $q;
    private int $maxSize;
    private int $droppedMoves = 0;
    private int $overflowCount = 0;

    public function __construct(int $maxSize = 5000)
    {
        $this->q = new SplQueue();
        $this->maxSize = max(100, $maxSize);
    }

    /** Them event. Tra ve true neu enqueue, false neu bi drop (chi move moi bi drop). */
    public function push(SyncEvent $e): bool
    {
        if ($e->isCritical()) {
            // Critical luon vao queue; neu tran thi hy sinh move cu de lay cho
            while ($this->q->count() >= $this->maxSize && $this->dropOldestMove()) {
                $this->droppedMoves++;
            }
            if ($this->q->count() >= $this->maxSize) {
                $this->overflowCount++; // van tran (toan critical): bao tran, dispatcher se xu ly
                return false;
            }
            $this->q->enqueue($e);
            return true;
        }
        // Move: neu cuoi queue da la move -> thay bang move moi nhat (coalesce)
        if (!$this->q->isEmpty()) {
            $tail = $this->q->top();
            if ($tail instanceof SyncEvent && $tail->isMove()) {
                $this->q->pop();
                $this->droppedMoves++;
            }
        }
        if ($this->q->count() >= $this->maxSize) {
            $this->overflowCount++;
            return false;
        }
        $this->q->enqueue($e);
        return true;
    }

    /** Bo 1 move cu nhat de lay cho critical. Tra ve true neu bo duoc. */
    private function dropOldestMove(): bool
    {
        $n = $this->q->count();
        for ($i = 0; $i < $n; $i++) {
            $e = $this->q->dequeue();
            if ($e instanceof SyncEvent && $e->isMove()) return true; // huy move nay
            $this->q->enqueue($e); // critical: xoay vong giu lai
        }
        return false;
    }

    public function shift(): ?SyncEvent
    {
        if ($this->q->isEmpty()) return null;
        $e = $this->q->dequeue();
        return $e instanceof SyncEvent ? $e : null;
    }

    public function isEmpty(): bool
    {
        return $this->q->isEmpty();
    }

    public function size(): int
    {
        return $this->q->count();
    }

    public function stats(): array
    {
        return ['size' => $this->q->count(), 'droppedMoves' => $this->droppedMoves, 'overflow' => $this->overflowCount];
    }
}
