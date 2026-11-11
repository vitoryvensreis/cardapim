<main class="status-page">
    <div class="status-shell">
        <header class="status-header">
            <a class="status-brand" href="/" aria-label="Cardapim, voltar à página inicial">
                <?php if ($logoPreta !== null): ?>
                    <img src="<?= htmlspecialchars($logoPreta, ENT_QUOTES, 'UTF-8') ?>" alt="Cardapim" width="190" height="61">
                <?php else: ?>
                    <span>Cardapim</span>
                <?php endif; ?>
            </a>
            <a class="status-back" href="/">Voltar ao site</a>
        </header>

        <section class="status-intro" aria-labelledby="status-title">
            <h1 id="status-title">Status do sistema</h1>
            <p>Verificação da execução do PHP e da conexão com o banco de dados.</p>
            <div class="status-overall <?= $databaseReady ? 'is-online' : 'is-offline' ?>" role="status">
                <span class="status-dot" aria-hidden="true"></span>
                <?= $databaseReady ? 'Todos os serviços verificados estão operacionais.' : 'Conexão com o banco de dados indisponível.' ?>
            </div>
        </section>

        <section class="status-services" aria-label="Serviços verificados">
            <article class="service-card">
                <div class="service-heading">
                    <h2>PHP</h2>
                    <span class="service-label is-online">Operacional</span>
                </div>
                <p>Aplicação PHP em execução.</p>
                <div class="service-detail">Versão <?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?></div>
            </article>

            <article class="service-card">
                <div class="service-heading">
                    <h2>Banco de dados</h2>
                    <span class="service-label <?= $databaseReady ? 'is-online' : 'is-offline' ?>">
                        <?= $databaseReady ? 'Conectado' : 'Indisponível' ?>
                    </span>
                </div>
                <p>Conexão MySQL e consulta de verificação.</p>
                <div class="service-detail"><?= $databaseReady ? 'Conexão validada' : 'Não foi possível conectar' ?></div>
            </article>
        </section>

        <footer class="status-footer">
            <span>Última verificação: <?= htmlspecialchars($checkedAt, ENT_QUOTES, 'UTF-8') ?></span>
            <span>Cardapim</span>
        </footer>
    </div>
</main>
