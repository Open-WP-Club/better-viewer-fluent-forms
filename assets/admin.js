(function () {
	'use strict';
	var settingsForm = document.querySelector('form[action="options.php"]');
	var settingsDirty = false;
	var settingsSubmitting = false;
	function markSettingsDirty() {
		if (!settingsForm) { return; }
		settingsDirty = true;
		var notice = document.querySelector('.cfs-unsaved-notice');
		if (notice) { notice.hidden = false; }
	}
	window.addEventListener('beforeunload', function (event) {
		if (!settingsDirty || settingsSubmitting) { return; }
		event.preventDefault();
		event.returnValue = cfsAdmin.unsaved;
		return cfsAdmin.unsaved;
	});
	function pickerRow(field, existing) {
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
		labelInput.type = 'text'; labelInput.className = 'regular-text cfs-client-label'; labelInput.value = existing ? existing.label : field.label;
		labelCell.appendChild(labelInput); row.appendChild(labelCell);
		var tableCell = document.createElement('td'); var tableInput = document.createElement('input');
		tableInput.type = 'checkbox'; tableInput.className = 'cfs-field-table'; tableInput.checked = !!(existing && existing.table);
		tableCell.appendChild(tableInput); row.appendChild(tableCell);
		var detailCell = document.createElement('td'); var detailInput = document.createElement('input');
		detailInput.type = 'checkbox'; detailInput.className = 'cfs-field-detail'; detailInput.checked = !!(existing && existing.detail);
		detailCell.appendChild(detailInput); row.appendChild(detailCell);
		var sectionCell = document.createElement('td'); var section = document.createElement('input');
		section.type = 'text'; section.className = 'cfs-field-section'; section.placeholder = cfsAdmin.sectionPlaceholder;
		section.value = (existing && existing.section) || cfsAdmin.defaultSection;
		sectionCell.appendChild(section); row.appendChild(sectionCell);
		return row;
	}
	/** Capture the picker's current on-screen state before it is rebuilt, so re-loading fields does not discard unsaved edits. */
	function readPickerState(card) {
		var state = {};
		card.querySelectorAll('.cfs-picker-table tbody tr').forEach(function (row) {
			var key = row.dataset.key;
			if (!key) { return; }
			state[key] = {
				label: row.querySelector('.cfs-client-label').value,
				table: row.querySelector('.cfs-field-table').checked,
				detail: row.querySelector('.cfs-field-detail').checked,
				section: row.querySelector('.cfs-field-section').value
			};
		});
		return state;
	}
	function renderPicker(container, fields, existingState) {
		container.replaceChildren();
		var table = document.createElement('table'); table.className = 'widefat striped cfs-picker-table';
		var headings = cfsAdmin.pickerHeadings;
		var thead = document.createElement('thead'); var headRow = document.createElement('tr');
		headings.forEach(function (text, index) {
			var th = document.createElement('th'); th.textContent = text;
			if (index === 2 || index === 3) {
				th.appendChild(document.createElement('br'));
				var toggle = document.createElement('input');
				toggle.type = 'checkbox'; toggle.className = 'cfs-toggle-column';
				toggle.dataset.target = index === 2 ? '.cfs-field-table' : '.cfs-field-detail';
				toggle.setAttribute('aria-label', cfsAdmin.toggleAll);
				th.appendChild(toggle);
			}
			headRow.appendChild(th);
		});
		thead.appendChild(headRow); table.appendChild(thead);
		var body = document.createElement('tbody');
		fields.forEach(function (field) { body.appendChild(pickerRow(field, existingState && existingState[field.key])); });
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
		var existingState = readPickerState(card);
		button.disabled = true;
		var data = new URLSearchParams({ action: 'cfs_form_fields', nonce: cfsAdmin.fieldsNonce, form_id: formId });
		fetch(cfsAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data })
			.then(function (response) { return response.json(); })
			.then(function (response) { if (!response.success) { throw new Error(); } renderPicker(card.querySelector('.cfs-field-picker'), response.data.fields, existingState); })
			.catch(function () { window.alert(cfsAdmin.fieldsError); })
			.finally(function () { button.disabled = false; });
	}
	function setInlineExpanded(row, expanded) {
		var toggle = row.querySelector('.cfs-inline-toggle');
		row.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		if (toggle) {
			toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			toggle.textContent = expanded ? cfsAdmin.close : cfsAdmin.view;
		}
	}
	function toggleInlineDetail(row) {
		var detailRow = row.nextElementSibling;
		if (detailRow && detailRow.classList.contains('cfs-inline-detail-row')) {
			if (detailRow.classList.contains('cfs-inline-error')) {
				detailRow.remove();
				toggleInlineDetail(row);
				return;
			}
			detailRow.hidden = !detailRow.hidden;
			setInlineExpanded(row, !detailRow.hidden);
			return;
		}
		detailRow = document.createElement('tr');
		detailRow.className = 'cfs-inline-detail-row';
		var cell = document.createElement('td');
		cell.colSpan = Number(row.dataset.colspan) || row.cells.length;
		var content = document.createElement('div');
		content.className = 'cfs-inline-detail';
		content.textContent = cfsAdmin.loading;
		cell.appendChild(content); detailRow.appendChild(cell);
		row.parentNode.insertBefore(detailRow, row.nextSibling);
		setInlineExpanded(row, true);
		var data = new FormData();
		data.append('action', 'cfs_submission_detail');
		data.append('nonce', cfsAdmin.detailNonce);
		data.append('form_id', row.dataset.form);
		data.append('submission_id', row.dataset.submission);
		fetch(cfsAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (!response.success || !response.data.html) { throw new Error(); }
				content.innerHTML = response.data.html;
				row.classList.remove('cfs-row-unread');
			})
			.catch(function () { detailRow.classList.add('cfs-inline-error'); content.textContent = cfsAdmin.detailError; });
	}
	document.addEventListener('change', function (event) {
		if (settingsForm && settingsForm.contains(event.target)) { markSettingsDirty(); }
		if (event.target.matches('.cfs-toggle-column')) {
			event.target.closest('table').querySelectorAll(event.target.dataset.target).forEach(function (checkbox) { checkbox.checked = event.target.checked; });
			return;
		}
		if (event.target.matches('.cfs-select-all')) {
			event.target.closest('table').querySelectorAll('tbody input[type="checkbox"]').forEach(function (checkbox) { checkbox.checked = event.target.checked; });
			return;
		}
		if (event.target.matches('select[name="date_preset"]') && event.target.value) {
			var filters = event.target.closest('.cfs-filters');
			filters.querySelector('input[name="date_from"]').value = '';
			filters.querySelector('input[name="date_to"]').value = '';
			return;
		}
		if (!event.target.matches('.cfs-form-id')) { return; }
		var select = event.target;
		var card = select.closest('.cfs-config-card');
		var title = card.querySelector('input[name$="[title]"]');
		var option = select.options[select.selectedIndex];
		if (title && option && option.dataset.title) { title.value = option.dataset.title; }
		if (select.value) { loadFields(select); }
	});
	document.addEventListener('click', function (event) {
		var addButton = event.target.closest('#cfs-add-form');
		if (addButton) {
			var template = document.querySelector('#cfs-form-template');
			var target = document.querySelector('#cfs-form-configs');
			if (template && target) {
				var index = String(Date.now());
				target.insertAdjacentHTML('beforeend', template.innerHTML.split('__INDEX__').join(index));
			}
			markSettingsDirty();
			return;
		}
		var removeButton = event.target.closest('.cfs-remove-form');
		if (removeButton) {
			removeButton.closest('.cfs-config-card').remove();
			markSettingsDirty();
			return;
		}
		var moveUp = event.target.closest('.cfs-move-form-up');
		if (moveUp) {
			var upCard = moveUp.closest('.cfs-config-card');
			var previous = upCard.previousElementSibling;
			if (previous) { upCard.parentNode.insertBefore(upCard, previous); }
			if (previous) { markSettingsDirty(); }
			return;
		}
		var moveDown = event.target.closest('.cfs-move-form-down');
		if (moveDown) {
			var downCard = moveDown.closest('.cfs-config-card');
			var next = downCard.nextElementSibling;
			if (next) { downCard.parentNode.insertBefore(next, downCard); }
			if (next) { markSettingsDirty(); }
			return;
		}
		var inlineToggle = event.target.closest('.cfs-inline-toggle');
		if (inlineToggle) {
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
			event.preventDefault();
			toggleInlineDetail(inlineToggle.closest('.cfs-submission-row'));
			return;
		}
		var submissionRow = event.target.closest('.cfs-submission-row');
		if (submissionRow && !event.target.closest('a, button, input, select, textarea, label')) {
			toggleInlineDetail(submissionRow);
			return;
		}
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
		settingsSubmitting = true;
		settingsDirty = false;
	});
	document.addEventListener('input', function (event) {
		if (settingsForm && settingsForm.contains(event.target)) { markSettingsDirty(); }
	});
	document.addEventListener('keydown', function (event) {
		if ((event.key !== 'Enter' && event.key !== ' ') || !event.target.matches('.cfs-submission-row')) { return; }
		event.preventDefault();
		toggleInlineDetail(event.target);
	});
}());
