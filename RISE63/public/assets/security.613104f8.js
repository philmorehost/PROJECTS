/* Live filter for the 250-row country table. Pure progressive enhancement:
   without JavaScript every row is still visible and each form still submits. */
(function () {
  'use strict';
  var input = document.getElementById('country-filter');
  var table = document.getElementById('country-table');
  if (!input || !table) { return; }
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
  var counter = document.createElement('p');
  counter.className = 'faint';
  table.parentNode.insertBefore(counter, table.parentNode.firstChild);

  function paint(value) {
    var term = value.trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (row) {
      var haystack = (row.dataset.country || '') + ' ' + row.textContent.toLowerCase();
      var match = term === '' || haystack.indexOf(term) !== -1;
      row.hidden = !match;
      if (match) { shown++; }
    });
    counter.textContent = (term === '' ? rows.length : shown) + ' of ' + rows.length + ' countries shown';
  }

  input.addEventListener('input', function () { paint(input.value); });
  paint('');
}());
