(function () {
	'use strict';
	function pickerRow(field) {
		var row = document.createElement('tr');
		row.dataset.key = field.key;
		var fieldCell = document.createElement('td');
		var label = document.createElement('strong');
		label.textContent = field.label;
		var key = document.createElement('code');
		key.textContent = field.key;
		fieldCell.append(label, document.createElement('br'), key);
		row.appendChild(fieldCell);
		var labelCell = document.createElement('td');
		var labelInput = document.createElement('input');
		labelInput.type = 'text'; labelInput.className = 'regular-text cfs-client-label'; labelInput.value = field.label;
		labelCell.appendChild(labelInput); row.appendChild(labelCell);
		['cfs-field-table', 'cfs-field-detail'].forEach(function (className) {
			var cell = document.createElement('td'); var input = document.createElement('input');
			input.type = 'checkbox'; input.className = className; cell.appendChild(input); row.appendChild(cell);
		});
		var sectionCell = document.createElement('td'); var section = document.createElement('input');
		section.type = 'text'; section.className = 'cfs-field-section'; section.placeholder = cfsAdmin.sectionPlaceholder; section.value = cfsAdmin.defaultSection;
		sectionCell.appendChild(section); row.appendChild(sectionCell);
		return row;
	}
	function renderPicker(container, fields) {
		container.replaceChildren();
		var table = document.createElement('table'); table.className = 'widefat striped cfs-picker-table';
		var headings = cfsAdmin.pickerHeadings;
		var thead = document.createElement('thead'); var headRow = document.createElement('tr');
		headings.forEach(function (text) { var th = document.createElement('th'); th.textContent = text; headRow.appendChild(th); });
		thead.appendChild(headRow); table.appendChild(thead);
		var body = document.createElement('tbody'); fields.forEach(function (field) { body.appendChild(pickerRow(field)); });
		table.appendChild(body); container.appendChild(table);
	}
	function serializePicker(card) {
		var columns = {}, sections = {};
		card.querySelectorAll('.cfs-picker-table tbody tr').forEach(function (row) {
			var key = row.dataset.key;
			var label = row.querySelector('.cfs-client-label').value.trim();
			if (!key || !label) { return; }
			if (row.querySelector('.cfs-field-table').checked) { columns[key] = label; }
			if (row.querySelector('.cfs-field-detail').checked) {
				var section = row.querySelector('.cfs-field-section').value.trim() || cfsAdmin.defaultSection;
				if (!sections[section]) { sections[section] = {}; }
				sections[section][key] = label;
			}
		});
		card.querySelector('.cfs-columns-json').value = JSON.stringify(columns);
		card.querySelector('.cfs-sections-json').value = JSON.stringify(sections);
	}
	function loadFields(button) {
		var card = button.closest('.cfs-config-card');
		var formId = card.querySelector('.cfs-form-id').value;
		if (!formId) { return; }
		button.disabled = true;
		var data = new URLSearchParams({ action: 'cfs_form_fields', nonce: cfsAdmin.fieldsNonce, form_id: formId });
		fetch(cfsAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data })
			.then(function (response) { return response.json(); })
			.then(function (response) { if (!response.success) { throw new Error(); } renderPicker(card.querySelector('.cfs-field-picker'), response.data.fields); })
			.catch(function () { window.alert(cfsAdmin.fieldsError); })
			.finally(function () { button.disabled = false; });
	}
	document.addEventListener('click', function (event) {
		var addButton = event.target.closest('#cfs-add-form');
		if (addButton) {
			var template = document.querySelector('#cfs-form-template');
			var target = document.querySelector('#cfs-form-configs');
			if (template && target) {
				var index = String(Date.now());
				target.insertAdjacentHTML('beforeend', template.innerHTML.split('__INDEX__').join(index));
			}
			return;
		}
		var removeButton = event.target.closest('.cfs-remove-form');
		if (removeButton) {
			removeButton.closest('.cfs-config-card').remove();
			return;
		}
		var loadButton = event.target.closest('.cfs-load-fields');
		if (loadButton) { loadFields(loadButton); return; }
		var button = event.target.closest('.cfs-status-actions button');
		if (!button) { return; }
		event.preventDefault();
		var wrap = button.closest('.cfs-status-actions');
		if (!wrap || button.disabled) { return; }
		button.disabled = true;
		var data = new FormData();
		data.append('action', 'cfs_update_status');
		data.append('nonce', cfsAdmin.nonce);
		data.append('form_id', wrap.dataset.form);
		data.append('submission_id', wrap.dataset.submission);
		data.append('status', button.dataset.status);
		fetch(cfsAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (!response.success) { throw new Error(); }
				wrap.querySelectorAll('button').forEach(function (item) { item.classList.remove('button-primary'); });
				button.classList.add('button-primary');
			})
			.catch(function () { window.alert(cfsAdmin.error); })
			.finally(function () { button.disabled = false; });
	});
	document.addEventListener('submit', function (event) {
		if (!event.target.matches('form[action="options.php"]')) { return; }
		event.target.querySelectorAll('.cfs-config-card').forEach(serializePicker);
	});
}());
