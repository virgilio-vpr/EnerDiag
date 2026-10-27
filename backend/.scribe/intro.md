# Introduction

API do EnerDiag para análise de faturas de energia do Grupo A.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    A API utiliza o prefixo `/api/v1` e troca dados em JSON. As respostas de erro seguem o formato `{message, code, errors}`.

    Endpoints protegidos exigem um token de acesso no cabeçalho `Authorization: Bearer {token}`. Os exemplos mostram respostas de sucesso e os erros documentados para cada endpoint.

