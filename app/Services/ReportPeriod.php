<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Período de referência do relatório.
 *
 * O documento pede que o utilizador escolha o período num calendário: a semana
 * pretendida (ex.: Semana 18/2026), o mês e o ano (Abril/2026), ou o ano (2026).
 * Daí este objecto aceitar `year`+`week`/`month` e não apenas períodos
 * relativos a hoje, como fazia o relatório antigo.
 */
class ReportPeriod
{
    public function __construct(
        public readonly string $type,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $label,
    ) {
    }

    public static function fromRequest(array $input): self
    {
        $type = $input['period'] ?? 'monthly';

        // Datas explícitas ganham sempre: permitem um intervalo arbitrário.
        if (! empty($input['start_date']) && ! empty($input['end_date'])) {
            $start = Carbon::parse($input['start_date'])->startOfDay();
            $end = Carbon::parse($input['end_date'])->endOfDay();

            if ($end->lessThan($start)) {
                throw ValidationException::withMessages([
                    'end_date' => 'A data final não pode ser anterior à inicial.',
                ]);
            }

            return new self('custom', $start, $end, sprintf(
                '%s a %s',
                $start->format('d/m/Y'),
                $end->format('d/m/Y')
            ));
        }

        $year = (int) ($input['year'] ?? now()->year);

        return match ($type) {
            'weekly' => self::week($year, isset($input['week']) ? (int) $input['week'] : (int) now()->isoWeek()),
            'yearly' => self::year($year),
            default => self::month($year, isset($input['month']) ? (int) $input['month'] : (int) now()->month),
        };
    }

    private static function week(int $year, int $week): self
    {
        if ($week < 1 || $week > 53) {
            throw ValidationException::withMessages([
                'week' => 'A semana tem de estar entre 1 e 53.',
            ]);
        }

        $start = Carbon::now()->setISODate($year, $week)->startOfWeek();

        return new self(
            'weekly',
            $start->copy()->startOfDay(),
            $start->copy()->endOfWeek()->endOfDay(),
            sprintf('Semana %d/%d (%s a %s)', $week, $year,
                $start->format('d/m'), $start->copy()->endOfWeek()->format('d/m/Y'))
        );
    }

    private static function month(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages([
                'month' => 'O mês tem de estar entre 1 e 12.',
            ]);
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return new self(
            'monthly',
            $start->copy()->startOfDay(),
            $start->copy()->endOfMonth()->endOfDay(),
            ucfirst($start->locale('pt')->translatedFormat('F \d\e Y'))
        );
    }

    private static function year(int $year): self
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();

        return new self(
            'yearly',
            $start->copy()->startOfDay(),
            $start->copy()->endOfYear()->endOfDay(),
            (string) $year
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
        ];
    }
}
