(function (Drupal, once) {
  'use strict';

  function applyVisibility(table) {
    const rows = Array.from(table.querySelectorAll('tr[data-hierarchy-level]'));
    const expandedByLevel = [];

    rows.forEach((row) => {
      const level = Number(row.dataset.hierarchyLevel || 0);
      const parentExpanded = level === 0 ? true : expandedByLevel[level - 1] === true;
      const isVisible = parentExpanded;
      row.style.display = isVisible ? 'table-row' : 'none';

      const isExpanded = row.dataset.expanded !== 'false';
      expandedByLevel[level] = parentExpanded && isExpanded;

      const toggle = row.querySelector('.views-tree-toggle');
      if (toggle) {
        toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      }
    });
  }

  Drupal.behaviors.kemkeViewsTreeTable = {
    attach(context) {
      once('kemke-views-tree-table', 'table.views-table', context).forEach((table) => {
        const rows = table.querySelectorAll('tr[data-hierarchy-level]');
        if (!rows.length) {
          return;
        }

        rows.forEach((row) => {
          const hasChildren = row.dataset.hasChildren === 'true';
          if (!row.dataset.expanded) {
            row.dataset.expanded = hasChildren ? 'false' : 'true';
          }
        });

        applyVisibility(table);

        table.querySelectorAll('.views-tree-toggle').forEach((button) => {
          button.addEventListener('click', () => {
            const row = button.closest('tr[data-hierarchy-level]');
            if (!row) {
              return;
            }
            const isExpanded = row.dataset.expanded === 'true';
            row.dataset.expanded = isExpanded ? 'false' : 'true';
            applyVisibility(table);
          });
        });
      });
    },
  };
})(Drupal, once);
