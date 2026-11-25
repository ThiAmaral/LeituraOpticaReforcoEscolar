/*
 * Dropdown com a cara do sistema para os <select> dos filtros (.filtros select).
 * O <select> original continua no formulário (escondido) e é ele que vai no envio; o botão e a
 * lista só mudam o valor dele. Sem JavaScript, fica o <select> normal.
 * Teclado: setas, Home/End, Enter/Espaço escolhem, Esc fecha.
 */
(function () {
  let contador = 0;

  function melhorar(select) {
    const id = 'dropdown-' + ++contador;
    const label = select.closest('label');
    const rotulo = label ? label.firstChild.textContent.trim() : select.name;

    const caixa = document.createElement('div');
    caixa.className = 'dropdown';

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'dropdown-botao';
    botao.setAttribute('aria-haspopup', 'listbox');
    botao.setAttribute('aria-expanded', 'false');
    botao.setAttribute('aria-controls', id);
    const texto = document.createElement('span');
    botao.append(texto);
    botao.insertAdjacentHTML('beforeend',
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>');

    const lista = document.createElement('ul');
    lista.className = 'dropdown-lista';
    lista.id = id;
    lista.setAttribute('role', 'listbox');
    lista.setAttribute('aria-label', rotulo);
    lista.tabIndex = -1;
    lista.hidden = true;

    const itens = Array.from(select.options).map((opcao, i) => {
      const li = document.createElement('li');
      li.id = id + '-' + i;
      li.setAttribute('role', 'option');
      li.textContent = opcao.textContent;
      li.addEventListener('click', () => escolher(i));
      li.addEventListener('mousemove', () => marcar(i));
      lista.append(li);
      return li;
    });

    let ativo = select.selectedIndex;

    function atualizar() {
      texto.textContent = select.options[select.selectedIndex]?.textContent ?? '';
      botao.setAttribute('aria-label', rotulo + ': ' + texto.textContent);
      itens.forEach((li, i) => li.setAttribute('aria-selected', String(i === select.selectedIndex)));
    }

    function marcar(i) {
      ativo = Math.max(0, Math.min(itens.length - 1, i));
      itens.forEach((li, j) => li.classList.toggle('ativo', j === ativo));
      lista.setAttribute('aria-activedescendant', itens[ativo].id);
      itens[ativo].scrollIntoView({ block: 'nearest' });
    }

    function abrir() {
      lista.hidden = false;
      botao.setAttribute('aria-expanded', 'true');
      marcar(select.selectedIndex);
      lista.focus();
    }

    function fechar(focarBotao) {
      lista.hidden = true;
      botao.setAttribute('aria-expanded', 'false');
      if (focarBotao) botao.focus();
    }

    function escolher(i) {
      if (select.selectedIndex !== i) {
        select.selectedIndex = i;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
      atualizar();
      fechar(true);
    }

    botao.addEventListener('click', () => (lista.hidden ? abrir() : fechar(true)));
    botao.addEventListener('keydown', (ev) => {
      if (['ArrowDown', 'ArrowUp'].includes(ev.key)) {
        ev.preventDefault();
        abrir();
      }
    });
    lista.addEventListener('keydown', (ev) => {
      const teclas = {
        ArrowDown: () => marcar(ativo + 1),
        ArrowUp: () => marcar(ativo - 1),
        Home: () => marcar(0),
        End: () => marcar(itens.length - 1),
        Enter: () => escolher(ativo),
        ' ': () => escolher(ativo),
        Escape: () => fechar(true),
      };
      if (teclas[ev.key]) {
        ev.preventDefault();
        teclas[ev.key]();
      } else if (ev.key === 'Tab') {
        fechar(false);
      }
    });
    lista.addEventListener('focusout', (ev) => {
      if (!caixa.contains(ev.relatedTarget)) fechar(false);
    });
    document.addEventListener('pointerdown', (ev) => {
      if (!caixa.contains(ev.target)) fechar(false);
    });
    // Clicar no texto do rótulo abre o dropdown em vez de focar o <select> escondido.
    if (label) {
      label.addEventListener('click', (ev) => {
        if (!caixa.contains(ev.target)) {
          ev.preventDefault();
          botao.focus();
        }
      });
    }

    select.hidden = true;
    select.tabIndex = -1;
    select.after(caixa);
    caixa.append(botao, lista);
    atualizar();
  }

  document.querySelectorAll('.filtros select').forEach(melhorar);
})();
