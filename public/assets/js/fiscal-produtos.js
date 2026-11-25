(() => {
    'use strict';
    const lote=document.querySelector('[data-fiscal-lote]');
    if (lote) {
        const linhas=[...lote.querySelectorAll('[data-fiscal-linha]')];
        const busca=lote.querySelector('[data-fiscal-busca]');
        const filtro=lote.querySelector('[data-fiscal-filtro]');
        const todos=lote.querySelector('[data-fiscal-todos]');
        const contagem=lote.querySelector('[data-fiscal-contagem]');
        const normalizar=v=>v.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
        const atualizar=()=>{
            const selecionados=linhas.filter(l=>l.querySelector('input').checked).length;
            const visiveis=linhas.filter(l=>!l.hidden);
            todos.checked=visiveis.length>0 && visiveis.every(l=>l.querySelector('input').checked);
            todos.indeterminate=!todos.checked && visiveis.some(l=>l.querySelector('input').checked);
            contagem.textContent=selecionados+' selecionados (máximo 200)';
        };
        const filtrar=()=>{
            for (const l of linhas) l.hidden=!(normalizar(l.dataset.nome).includes(normalizar(busca.value)) && (!filtro.value || l.dataset.categoria===filtro.value));
            atualizar();
        };
        busca.addEventListener('input',filtrar);
        filtro.addEventListener('change',filtrar);
        todos.addEventListener('change',()=>{for (const l of linhas) if (!l.hidden) l.querySelector('input').checked=todos.checked; atualizar();});
        lote.addEventListener('change',atualizar);
        atualizar();
    }
    const form=document.querySelector('[data-fiscal-classificacao]');
    if (!form) return;
    const btn=form.querySelector('[data-ncm-pesquisar]');
    const busca=form.querySelector('[data-ncm-busca]');
    const status=form.querySelector('[data-ncm-status]');
    const resultados=form.querySelector('[data-ncm-resultados]');
    const ncm=form.elements.namedItem('ncm');
    btn.addEventListener('click',async()=>{
        if (busca.value.trim().length<2) { status.textContent='Digite pelo menos dois caracteres.'; return; }
        btn.disabled=true; status.textContent='Consultando BrasilAPI...'; resultados.replaceChildren();
        try {
            const r=await fetch(form.dataset.ncmUrl+'?q='+encodeURIComponent(busca.value.trim()),{headers:{Accept:'application/json'},credentials:'same-origin'});
            const d=await r.json();
            if (!r.ok || !d.ok) throw new Error(d.mensagem||'Consulta indisponível.');
            status.textContent=d.resultados.length?d.fonte+' — escolha somente a classificação compatível.':'Nenhum resultado. Tente outro termo ou código.';
            for (const item of d.resultados) {
                const b=document.createElement('button'); b.type='button'; b.className='btn btn-outline'; b.textContent=item.codigo+' — '+item.descricao+' (vigência: '+item.data_inicio+' a '+item.data_fim+')';
                b.addEventListener('click',()=>{ncm.value=item.codigo; const conf=form.elements.namedItem('confirmado'); if(conf) conf.checked=false; const marcar=form.querySelector('input[name="campos[]"][value="ncm"]'); if (marcar) marcar.checked=true; status.textContent='NCM selecionado. Confira antes de salvar.'; resultados.replaceChildren();});
                resultados.append(b);
            }
        } catch(e) { status.textContent=e.message||'Consulta indisponível. Você pode salvar como pendente.'; }
        finally { btn.disabled=false; }
    });
    busca.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();btn.click();}});
    const gtin=form.querySelector('[data-fiscal-gtin]');
    if (gtin) gtin.addEventListener('click',()=>{form.elements.namedItem('gtin').value=gtin.dataset.fiscalGtin;});
    const consultaGtin=form.querySelector('[data-fiscal-gtin-consultar]');
    if (consultaGtin) consultaGtin.addEventListener('click',async()=>{
        const campo=form.elements.namedItem('gtin');
        const codigo=campo.value.trim();
        const msg=form.querySelector('[data-fiscal-gtin-status]');
        const out=form.querySelector('[data-fiscal-gtin-resultado]');
        out.replaceChildren();
        if (!/^(?:\d{8}|\d{12,14})$/.test(codigo)) { msg.textContent='Informe um código de barras válido.';return; }
        consultaGtin.disabled=true; msg.textContent='Consultando NeXT...';
        try {
            const r=await fetch(form.dataset.gtinUrl,{method:'POST',headers:{Accept:'application/json'},credentials:'same-origin',body:new URLSearchParams({_csrf:form.elements.namedItem('_csrf').value,gtin:codigo})});
            const d=await r.json();
            if (!r.ok || !d.ok) throw new Error(d.mensagem||'Consulta indisponível.');
            if (campo.value.trim()!==codigo) { msg.textContent='O código foi alterado. Consulte novamente.';return; }
            msg.textContent=d.dados.fonte+' — '+d.dados.descricao;
            const b=document.createElement('button');b.type='button';b.className='btn btn-outline';
            b.textContent='Usar NCM '+d.dados.ncm+(d.dados.cest?' e CEST '+d.dados.cest:'')+' como sugestão';
            b.addEventListener('click',()=>{
                if (campo.value.trim()!==codigo) { msg.textContent='O código foi alterado. Consulte novamente.';out.replaceChildren();return; }
                const cest=form.elements.namedItem('cest');
                if ((ncm.value && ncm.value!==d.dados.ncm)||(d.dados.cest && cest.value && cest.value!==d.dados.cest)) {
                    if (!window.confirm('Substituir a classificação que está no formulário?')) return;
                }
                ncm.value=d.dados.ncm;if(d.dados.cest)cest.value=d.dados.cest;
                form.elements.namedItem('confirmado').checked=false;
                msg.textContent='Sugestão carregada. Confira a classificação antes de salvar.';out.replaceChildren();
            });
            out.append(b);
        } catch(e){msg.textContent=e.message||'Consulta indisponível.';}
        finally{consultaGtin.disabled=false;}
    });
})();
