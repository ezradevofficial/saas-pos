/* @ds-bundle: {"format":4,"namespace":"DS","components":[{"name":"Button"},{"name":"TextField"},{"name":"Select"},{"name":"Checkbox"},{"name":"Switch"},{"name":"StatusBadge"},{"name":"Alert"},{"name":"SyncStatus"},{"name":"Money"},{"name":"KpiTile"},{"name":"DataTable"},{"name":"Card"},{"name":"Tabs"},{"name":"Dialog"},{"name":"PosTile"},{"name":"SaleTotal"},{"name":"StageTracker"},{"name":"ApprovalCard"}]} */
(function () {
  var React = window.React;
  var h = React.createElement;

  function cx() {
    var out = [];
    for (var i = 0; i < arguments.length; i++) if (arguments[i]) out.push(arguments[i]);
    return out.join(' ');
  }
  function omit(obj, keys) {
    var o = {};
    for (var k in obj) if (Object.prototype.hasOwnProperty.call(obj, k) && keys.indexOf(k) < 0) o[k] = obj[k];
    return o;
  }

  var ICONS = {
    check: 'M5 12l5 5L20 7',
    x: 'M6 6l12 12M18 6L6 18',
    alert: 'M12 8v5M12 16.5v.5M10.3 3.9L2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    info: 'M12 11v6M12 7.5v.5M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
    cloud: 'M7 18h10a4 4 0 0 0 .7-7.9A6 6 0 0 0 6.1 9.2 4.5 4.5 0 0 0 7 18z',
    sync: 'M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4',
    offline: 'M3 3l18 18M8.5 16.5a5 5 0 0 1 7 0M5 13a10 10 0 0 1 4.2-2.4M19 13a10 10 0 0 0-2.6-1.8M2 9.5a15 15 0 0 1 4.6-2.8M22 9.5A15 15 0 0 0 12 5.5c-.7 0-1.4 0-2 .1M12 20h.01',
    chevron: 'M6 9l6 6 6-6',
    clock: 'M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z'
  };
  function Icon(props) {
    return h('svg', {
      className: cx('ds-icon', props.className), viewBox: '0 0 24 24', width: props.size || 16, height: props.size || 16,
      fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true'
    }, h('path', { d: ICONS[props.name] }));
  }

  function formatAmount(amount, currency, locale) {
    var decimals = currency === 'CDF' ? 0 : 2;
    var n = Number(amount) || 0;
    try {
      return new Intl.NumberFormat(locale || 'en-KE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(n);
    } catch (e) {
      return n.toFixed(decimals);
    }
  }

  /* ---------- Actions ---------- */
  function Button(props) {
    var variant = props.variant || 'secondary';
    var size = props.size || 'md';
    var rest = omit(props, ['variant', 'size', 'icon', 'block', 'className', 'children', 'loading']);
    return h('button', Object.assign({ type: 'button' }, rest, {
      className: cx('ds-btn', 'ds-btn-' + variant, 'ds-btn-' + size, props.block && 'ds-btn-block', props.loading && 'ds-is-loading', props.className),
      'aria-busy': props.loading ? 'true' : undefined,
      disabled: props.disabled || props.loading
    }), props.icon ? h(Icon, { name: props.icon }) : null, h('span', null, props.children));
  }

  /* ---------- Forms ---------- */
  var uid = 0;
  function useId(given) {
    var ref = React.useRef(null);
    if (!ref.current) ref.current = given || 'ds-f' + (++uid);
    return ref.current;
  }
  function Field(props, control) {
    return h('div', { className: cx('ds-field', props.error && 'ds-field-error', props.className) },
      props.label ? h('label', { className: 'ds-label', htmlFor: props.id }, props.label, props.required ? h('span', { className: 'ds-req', 'aria-hidden': 'true' }, ' *') : null) : null,
      control,
      props.error ? h('div', { className: 'ds-help ds-help-error', id: props.id + '-msg' }, h(Icon, { name: 'alert', size: 14 }), props.error)
        : props.help ? h('div', { className: 'ds-help', id: props.id + '-msg' }, props.help) : null);
  }
  function TextField(props) {
    var id = useId(props.id);
    var rest = omit(props, ['label', 'help', 'error', 'prefix', 'suffix', 'className', 'id', 'required']);
    var input = h('div', { className: 'ds-input-wrap' },
      props.prefix ? h('span', { className: 'ds-affix' }, props.prefix) : null,
      h('input', Object.assign({ className: 'ds-input', id: id, required: props.required, 'aria-invalid': props.error ? 'true' : undefined,
        'aria-describedby': (props.error || props.help) ? id + '-msg' : undefined }, rest)),
      props.suffix ? h('span', { className: 'ds-affix' }, props.suffix) : null);
    return Field(Object.assign({}, props, { id: id }), input);
  }
  function Select(props) {
    var id = useId(props.id);
    var rest = omit(props, ['label', 'help', 'error', 'options', 'className', 'id', 'placeholder', 'required']);
    var opts = (props.options || []).map(function (o) {
      var v = typeof o === 'string' ? o : o.value;
      return h('option', { key: v, value: v }, typeof o === 'string' ? o : o.label);
    });
    if (props.placeholder) opts.unshift(h('option', { key: '__ph', value: '', disabled: true }, props.placeholder));
    var control = h('div', { className: 'ds-input-wrap ds-select-wrap' },
      h('select', Object.assign({ className: 'ds-input ds-select', id: id, required: props.required, 'aria-invalid': props.error ? 'true' : undefined,
        'aria-describedby': (props.error || props.help) ? id + '-msg' : undefined }, rest), opts),
      h(Icon, { name: 'chevron', className: 'ds-select-chev' }));
    return Field(Object.assign({}, props, { id: id }), control);
  }
  function Checkbox(props) {
    var id = useId(props.id);
    var rest = omit(props, ['label', 'help', 'className', 'id']);
    return h('div', { className: cx('ds-check', props.className) },
      h('input', Object.assign({ type: 'checkbox', id: id, className: 'ds-check-input' }, rest)),
      h('label', { htmlFor: id, className: 'ds-check-label' }, props.label,
        props.help ? h('span', { className: 'ds-help' }, props.help) : null));
  }
  function Switch(props) {
    var id = useId(props.id);
    var on = !!props.checked;
    return h('div', { className: cx('ds-switch-row', props.className) },
      h('button', {
        type: 'button', role: 'switch', id: id, 'aria-checked': on ? 'true' : 'false', disabled: props.disabled,
        className: cx('ds-switch', on && 'ds-switch-on'),
        onClick: function () { if (props.onChange) props.onChange(!on); }
      }, h('span', { className: 'ds-switch-knob' })),
      props.label ? h('label', { htmlFor: id, className: 'ds-check-label' }, props.label) : null);
  }

  /* ---------- Status ---------- */
  var TONE_ICON = { success: 'check', warning: 'alert', danger: 'x', info: 'info' };
  function StatusBadge(props) {
    var tone = props.tone || 'neutral';
    return h('span', { className: cx('ds-badge', 'ds-badge-' + tone, props.className) },
      h('span', { className: 'ds-badge-dot', 'aria-hidden': 'true' }),
      props.children);
  }
  function Alert(props) {
    var tone = props.tone || 'info';
    return h('div', { className: cx('ds-alert', 'ds-alert-' + tone, props.className), role: tone === 'danger' ? 'alert' : 'status' },
      h(Icon, { name: TONE_ICON[tone] || 'info', size: 18 }),
      h('div', { className: 'ds-alert-body' },
        props.title ? h('div', { className: 'ds-alert-title' }, props.title) : null,
        props.children ? h('div', { className: 'ds-alert-text' }, props.children) : null),
      props.action ? h('div', { className: 'ds-alert-action' }, props.action) : null);
  }
  function SyncStatus(props) {
    var state = props.state || 'online';
    var map = {
      online: { icon: 'cloud', text: props.labels && props.labels.online || 'Online · all synced', tone: 'success' },
      syncing: { icon: 'sync', text: props.labels && props.labels.syncing || 'Syncing…', tone: 'info' },
      offline: { icon: 'offline', text: props.labels && props.labels.offline || 'Offline · selling continues', tone: 'warning' }
    };
    var s = map[state];
    return h('span', { className: cx('ds-sync', 'ds-sync-' + s.tone, props.className), role: 'status' },
      h(Icon, { name: s.icon, size: 14, className: state === 'syncing' ? 'ds-spin' : null }),
      h('span', null, s.text),
      props.pending ? h('span', { className: 'ds-sync-count' }, props.pending + (props.pending === 1 ? ' sale waiting' : ' sales waiting')) : null);
  }

  /* ---------- Data ---------- */
  function Money(props) {
    var size = props.size || 'md';
    return h('span', { className: cx('ds-money', 'ds-money-' + size, props.tone && 'ds-text-' + props.tone, props.className) },
      h('span', { className: 'ds-money-main' },
        h('span', { className: 'ds-money-cur' }, props.currency), ' ', formatAmount(props.amount, props.currency, props.locale)),
      props.secondary ? h('span', { className: 'ds-money-sec' },
        '≈ ' + props.secondary.currency + ' ' + formatAmount(props.secondary.amount, props.secondary.currency, props.locale)) : null);
  }
  function KpiTile(props) {
    var dir = props.delta == null ? null : (props.delta >= 0 ? 'up' : 'down');
    var good = dir == null ? null : ((dir === 'up') !== !!props.lowerIsBetter);
    return h('div', { className: cx('ds-kpi', props.className) },
      h('div', { className: 'ds-kpi-label' }, props.label),
      h('div', { className: 'ds-kpi-value' }, props.value),
      dir ? h('div', { className: cx('ds-kpi-delta', good ? 'ds-text-success' : 'ds-text-danger') },
        (dir === 'up' ? '▲ ' : '▼ ') + Math.abs(props.delta) + '% ', h('span', { className: 'ds-kpi-period' }, props.period || 'vs last week')) : null);
  }
  function DataTable(props) {
    var cols = props.columns || [];
    var rows = props.rows || [];
    return h('div', { className: cx('ds-table-wrap', props.className) },
      h('table', { className: 'ds-table' },
        props.caption ? h('caption', { className: 'ds-sr' }, props.caption) : null,
        h('thead', null, h('tr', null, cols.map(function (c) {
          return h('th', { key: c.key, scope: 'col', className: c.align === 'end' ? 'ds-end' : null }, c.label);
        }))),
        h('tbody', null, rows.length ? rows.map(function (r, i) {
          return h('tr', { key: r.id || i, className: props.selectedId && r.id === props.selectedId ? 'ds-row-selected' : null,
            onClick: props.onRowClick ? function () { props.onRowClick(r); } : null },
            cols.map(function (c) {
              var v = c.render ? c.render(r) : r[c.key];
              return h('td', { key: c.key, className: cx(c.align === 'end' && 'ds-end', c.numeric && 'ds-num') }, v);
            }));
        }) : h('tr', null, h('td', { colSpan: cols.length, className: 'ds-empty' }, props.emptyText || 'Nothing here yet.')))));
  }

  /* ---------- Layout ---------- */
  function Card(props) {
    return h('section', { className: cx('ds-card', props.className) },
      (props.title || props.actions) ? h('header', { className: 'ds-card-head' },
        h('div', null, props.title ? h('h3', { className: 'ds-card-title' }, props.title) : null,
          props.subtitle ? h('div', { className: 'ds-card-sub' }, props.subtitle) : null),
        props.actions ? h('div', { className: 'ds-card-actions' }, props.actions) : null) : null,
      h('div', { className: 'ds-card-body' }, props.children));
  }
  function Tabs(props) {
    var items = props.items || [];
    return h('div', { className: cx('ds-tabs', props.className), role: 'tablist' },
      items.map(function (it) {
        var active = it.value === props.value;
        return h('button', { key: it.value, type: 'button', role: 'tab', 'aria-selected': active ? 'true' : 'false',
          className: cx('ds-tab', active && 'ds-tab-active'), onClick: function () { if (props.onChange) props.onChange(it.value); } },
          it.label, it.count != null ? h('span', { className: 'ds-tab-count' }, it.count) : null);
      }));
  }
  function Dialog(props) {
    if (!props.open) return null;
    var panel = h('div', { className: cx('ds-dialog', props.size && 'ds-dialog-' + props.size), role: 'dialog', 'aria-modal': 'true', 'aria-label': props.title },
      h('header', { className: 'ds-dialog-head' },
        h('h2', { className: 'ds-dialog-title' }, props.title),
        props.onClose ? h('button', { type: 'button', className: 'ds-icon-btn', 'aria-label': 'Close', onClick: props.onClose }, h(Icon, { name: 'x', size: 18 })) : null),
      h('div', { className: 'ds-dialog-body' }, props.children),
      props.footer ? h('footer', { className: 'ds-dialog-foot' }, props.footer) : null);
    return props.inline ? panel : h('div', { className: 'ds-overlay', onClick: function (e) { if (e.target === e.currentTarget && props.onClose) props.onClose(); } }, panel);
  }

  /* ---------- POS ---------- */
  function PosTile(props) {
    var out = props.stock === 0;
    return h('button', {
      type: 'button', className: cx('ds-tile', out && 'ds-tile-out', props.className), disabled: out,
      onClick: props.onSelect,
      'aria-label': props.name + ', ' + props.currency + ' ' + formatAmount(props.price, props.currency) + (out ? ', out of stock' : '')
    },
      props.image ? h('img', { className: 'ds-tile-img', src: props.image, alt: '' }) : null,
      h('span', { className: 'ds-tile-name' }, props.color ? h('span', { className: 'ds-tile-cat', style: { background: props.color }, 'aria-hidden': 'true' }) : null, props.name),
      h('span', { className: 'ds-tile-foot' },
        h('span', { className: 'ds-tile-price' }, props.currency + ' ' + formatAmount(props.price, props.currency)),
        out ? h(StatusBadge, { tone: 'danger' }, 'Out') : props.stock != null && props.stock <= (props.lowStock || 5) ? h(StatusBadge, { tone: 'warning' }, props.stock + ' left') : null));
  }
  function SaleTotal(props) {
    var c = props.currency;
    function row(label, amount, cls) {
      return h('div', { className: cx('ds-total-row', cls) }, h('span', null, label), h('span', { className: 'ds-num' }, c + ' ' + formatAmount(amount, c)));
    }
    return h('div', { className: cx('ds-total', props.className) },
      row(props.labels && props.labels.subtotal || 'Subtotal', props.subtotal),
      props.discount ? row(props.labels && props.labels.discount || 'Discount', -props.discount, 'ds-text-accent') : null,
      row(props.labels && props.labels.tax || 'VAT', props.tax),
      h('div', { className: 'ds-total-due' },
        h('span', { className: 'ds-total-label' }, props.labels && props.labels.total || 'Total'),
        h(Money, { amount: props.total, currency: c, size: 'lg', secondary: props.secondary })),
      h(Button, { variant: 'pay', size: 'lg', block: true, onClick: props.onPay, disabled: !props.total },
        (props.labels && props.labels.pay || 'Charge') + ' ' + c + ' ' + formatAmount(props.total, c)));
  }

  /* ---------- Workflow ---------- */
  function StageTracker(props) {
    var stages = props.stages || [];
    var cur = stages.indexOf(props.current);
    return h('ol', { className: cx('ds-stages', props.className) },
      stages.map(function (s, i) {
        var state = i < cur ? 'done' : i === cur ? (props.blocked ? 'blocked' : 'current') : 'todo';
        return h('li', { key: s, className: 'ds-stage ds-stage-' + state, 'aria-current': i === cur ? 'step' : undefined },
          h('span', { className: 'ds-stage-dot' }, state === 'done' ? h(Icon, { name: 'check', size: 12 }) : state === 'blocked' ? h(Icon, { name: 'x', size: 12 }) : i + 1),
          h('span', { className: 'ds-stage-label' }, s));
      }));
  }
  function ApprovalCard(props) {
    return h('article', { className: cx('ds-approval', props.overdue && 'ds-approval-overdue', props.className) },
      h('header', { className: 'ds-approval-head' },
        h('div', null,
          h('div', { className: 'ds-approval-type' }, props.docType + ' · ' + props.number),
          h('div', { className: 'ds-approval-title' }, props.title)),
        props.amount != null ? h(Money, { amount: props.amount, currency: props.currency }) : null),
      h('dl', { className: 'ds-approval-meta' },
        h('div', null, h('dt', null, 'Requested by'), h('dd', null, props.requester)),
        props.branch ? h('div', null, h('dt', null, 'Branch'), h('dd', null, props.branch)) : null,
        props.onBehalfOf ? h('div', null, h('dt', null, 'Delegated from'), h('dd', null, props.onBehalfOf)) : null),
      h('div', { className: 'ds-approval-due' }, h(Icon, { name: 'clock', size: 14 }),
        props.overdue ? h(StatusBadge, { tone: 'danger' }, 'Overdue · escalates ' + (props.escalatesIn || 'soon')) : h('span', null, props.due)),
      h('footer', { className: 'ds-approval-actions' },
        h(Button, { variant: 'ghost', onClick: props.onReturn }, 'Return'),
        h(Button, { variant: 'danger', onClick: props.onReject }, 'Reject'),
        h(Button, { variant: 'primary', onClick: props.onApprove }, 'Approve')));
  }

  var api = {
    Button: Button, TextField: TextField, Select: Select, Checkbox: Checkbox, Switch: Switch,
    StatusBadge: StatusBadge, Alert: Alert, SyncStatus: SyncStatus,
    Money: Money, KpiTile: KpiTile, DataTable: DataTable,
    Card: Card, Tabs: Tabs, Dialog: Dialog,
    PosTile: PosTile, SaleTotal: SaleTotal,
    StageTracker: StageTracker, ApprovalCard: ApprovalCard,
    formatAmount: formatAmount
  };
  window.DS = Object.assign(window.DS || {}, api);
})();
