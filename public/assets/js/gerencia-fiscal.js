(() => {
    'use strict';
    const form = document.querySelector('[data-fiscal-empresa]');
    if (!form) return;
    const btn = form.querySelector('[data-fiscal-repreencher]');
    const cepBtn = form.querySelector('[data-fiscal-cep]');
    const mensagem = form.querySelector('[data-fiscal-mensagem]');
    const campos = ['razao','nome','email','cep','logradouro','numero','complemento','bairro','cidade','estado','crt'];
    let revisao = 0;
    let ocupado = false;
    const campo = nome => form.elements.namedItem(nome);
    const avisar = (texto, erro = false) => {
        mensagem.textContent = texto;
        mensagem.classList.toggle('fiscal-consulta-erro', erro);
    };
    form.addEventListener('input', () => { revisao++; });
    form.addEventListener('change', () => { revisao++; });
    function ajustarIe() {
        const possui = campo('ie_situacao').value === 'possui';
        campo('ie').required = possui;
        campo('ie').disabled = !possui;
        form.querySelector('[data-fiscal-ie]').hidden = !possui;
    }
    campo('ie_situacao').addEventListener('change', ajustarIe);
    ajustarIe();
    function bloquear(valor) {
        ocupado = valor;
        btn.disabled = valor;
        cepBtn.disabled = valor;
        form.setAttribute('aria-busy', String(valor));
    }
    async function preencher(automatico) {
        if (ocupado) return;
        if (!automatico && !window.confirm('Repreencher os campos encontrados no cadastro do CNPJ? Você poderá editar os dados antes de salvar.')) return;
        const inicio = revisao;
        bloquear(true);
        avisar('Consultando os dados do CNPJ...');
        try {
            const dados = new URLSearchParams({_csrf: campo('_csrf').value, atualizar: automatico ? '0' : '1'});
            const r = await fetch(form.dataset.fiscalConsulta, {method:'POST', body:dados, credentials:'same-origin', headers:{Accept:'application/json'}});
            if (!r.ok) throw new Error();
            const d = await r.json();
            if (d.ok !== true || !d.dados) throw new Error();
            if (revisao !== inicio) {
                avisar('Você editou o formulário durante a consulta. Nada foi substituído. Use Repreencher se desejar.');
                return;
            }
            for (const k of campos) {
                if (typeof d.dados[k] === 'string' && d.dados[k] !== '') campo(k).value = d.dados[k];
            }
            revisao++;
            avisar('Dados encontrados. Confira com o contador, informe a inscrição estadual e salve. Nenhum dado foi salvo automaticamente.');
        } catch {
            avisar('Não foi possível consultar o CNPJ agora. Você pode tentar novamente ou preencher manualmente.',true);
        } finally { bloquear(false); }
    }
    async function buscarCep() {
        if (ocupado) return;
        const cep = campo('cep').value.replace(/\D/g,'');
        if (cep.length !== 8) { avisar('Informe um CEP de 8 dígitos.',true); return; }
        const inicio = revisao;
        bloquear(true);
        avisar('Buscando endereço pelo CEP...');
        try {
            const r = await fetch('/api/v1/cep/' + cep,{headers:{Accept:'application/json'}});
            if (!r.ok) throw new Error();
            const d = await r.json();
            if (revisao !== inicio || campo('cep').value.replace(/\D/g,'') !== cep) return;
            for (const k of ['logradouro','bairro','cidade','estado']) {
                if (typeof d[k] === 'string' && d[k] !== '') campo(k).value = d[k].slice(0,campo(k).maxLength);
            }
            revisao++;
            avisar('Endereço encontrado. Confira o número e o complemento antes de salvar.');
        } catch {
            avisar('CEP não encontrado ou serviço indisponível. Preencha o endereço manualmente.',true);
        } finally { bloquear(false); }
    }
    btn.addEventListener('click', () => preencher(false));
    cepBtn.addEventListener('click', buscarCep);
    campo('cep').addEventListener('change', () => {
        if (campo('cep').value.replace(/\D/g,'').length === 8) buscarCep();
    });
    if (form.dataset.fiscalInicial === '1') preencher(true);
})();
