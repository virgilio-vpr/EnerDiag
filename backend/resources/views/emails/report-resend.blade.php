<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Seu relatório EnerDiag</title>
</head>
<body>
    <h1>Seu relatório de eficiência energética</h1>
    <p>Olá, {{ $customerName }}.</p>
    <p>Estamos reenviando o resumo da sua análise.</p>
    <p>Economia mensal média: R$ {{ $report->total_monthly_savings }}</p>
    <p>Projeção anual: R$ {{ $report->total_yearly_savings }}</p>
    <p>Percentual do valor médio pago no período: {{ $report->savings_percentage }}%</p>

    @if ($report->explanatory_texts)
        <h2>Explicações</h2>
        @foreach ($report->explanatory_texts as $text)
            <p>{{ $text }}</p>
        @endforeach
    @endif

    @if ($report->recommendations)
        <h2>Recomendações</h2>
        @foreach ($report->recommendations as $recommendation)
            <p>{{ $recommendation }}</p>
        @endforeach
    @endif
</body>
</html>
