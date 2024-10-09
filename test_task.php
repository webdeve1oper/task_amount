<?php
  public function deductBalance(float $amount) {
        $amount = round($amount, 8);

        if ($amount <= 0) {
            return redirect()->back()->withErrors(['error' => 'сумма не может быть отрицательной или равна нулю']);
        }

        if ($amount > $this->balance) {
            return redirect()->back()->withErrors(['error' => 'недостаточно баланса']);
        }

        $this->balance -= $amount;
        return $this->balance;
    }

    public function addBalance(float $amount) {
        $amount = round($amount, 8);

        if ($amount <= 0) {
            return redirect()->back()->withErrors(['error' => 'сумма не может быть отрицательной или равна нулю']);
        }

        $this->balance += $amount;
        return $this->balance;
    }
