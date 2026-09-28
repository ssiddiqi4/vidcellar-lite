/**
 * Copyright (C) 2026  Suhaib Siddiqi
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/**
 * 30 GB resumable chunk uploader.
 * Progress is displayed as a percentage of the selected video's actual size,
 * with uploaded/total bytes and chunk counts shown underneath.
 *
 * A File object cannot be restored by a browser after refresh for security
 * reasons, so resume-after-refresh works by remembering the server upload key
 * and asking the administrator to select the same local file again. The
 * server reports which chunks already exist and the browser skips them.
 */
(function () {
	'use strict';

	var settings = window.vidcellarUpload || {};
	if (!settings.ajaxUrl || !settings.nonce || !settings.chunkSize) return;

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('vc-add-video-form');
		var fileInput = document.getElementById('vc_video_file');
		if (!form || !fileInput) return;

		var progressWrap = document.getElementById('vc-video-upload-progress');
		var progressBar = document.getElementById('vc-video-upload-progress-bar');
		var progressLabel = document.getElementById('vc-video-upload-progress-label');
		var progressDetail = document.getElementById('vc-video-upload-progress-detail');
		var cancelButton = document.getElementById('vc-video-upload-cancel');
		var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
		var keyField = document.getElementById('vc_chunked_video_key');
		var nameField = document.getElementById('vc_chunked_video_name');
		var alreadyChunked = false;
		var busy = false;
		var activeUpload = null;
		var storageKey = 'vidcellar_upload_v4';

		form.addEventListener('submit', function (event) {
			if (alreadyChunked) return;
			if (!fileInput.files || !fileInput.files[0]) return;
			event.preventDefault();
			startOrResume(fileInput.files[0]);
		});

		fileInput.addEventListener('change', function () {
			if (fileInput.files && fileInput.files[0]) {
				var pending = loadPending();
				if (pending && sameFileIdentity(pending, fileInput.files[0])) {
					showResumeNotice(pending);
				}
			}
		});

		if (cancelButton) {
			cancelButton.addEventListener('click', function () {
				if (!activeUpload) return;
				cancelUpload(activeUpload.key).then(function () {
					clearPending();
					resetUploadUI();
				});
			});
		}

		var initialPending = loadPending();
		if (initialPending && progressLabel) {
			progressWrap.style.display = 'block';
			progressLabel.textContent = 'Resume available';
			if (progressDetail) progressDetail.textContent = 'Select the same file again to continue from ' + formatFileSize(initialPending.bytesReceived || 0) + ' of ' + formatFileSize(initialPending.size || 0) + '.';
		}

		async function startOrResume(file) {
			if (busy) return;

			if (file.size > Number(settings.maxFileSize || 32212254720)) {
				fail('This video is larger than the 30 GB upload limit.');
				return;
			}
			if (!file.size) {
				fail('The selected video is empty.');
				return;
			}

			var chunkSize = Number(settings.chunkSize);
			var totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
			var pending = loadPending();
			var uploadKey = null;
			var received = [];
			var sessionExists = false;

			setBusy(true);
			try {
				if (pending && sameFileIdentity(pending, file)) {
					var status = await requestStatus(pending.key);
					if (status && status.found && Number(status.fileSize) === file.size && Number(status.totalChunks) === totalChunks) {
						uploadKey = pending.key;
						sessionExists = true;
						received = (status.received || []).map(Number).filter(function (n) { return n >= 0 && n < totalChunks; });
						if (status.complete) {
							completeUpload(uploadKey, file.name, file.size, upload.totalChunks);
							return;
						}
					} else {
						sessionExists = false;
						clearPending();
					}
				}

				if (!uploadKey) {
					uploadKey = generateUploadKey();
					sessionExists = false;
					received = [];
					savePending({
						key: uploadKey,
						name: file.name,
						size: file.size,
						lastModified: file.lastModified || 0,
						totalChunks: totalChunks,
						bytesReceived: 0
					});
				}

				activeUpload = { key: uploadKey, file: file, chunkSize: chunkSize, totalChunks: totalChunks, received: received };
				showProgress(received, totalChunks, file.size, sessionExists ? 'Resuming upload…' : 'Starting upload…');
				await uploadRemainingChunks(activeUpload, sessionExists);
			} catch (error) {
				fail(error && error.message ? error.message : 'The video upload was interrupted. Your progress is saved; select the same file again to resume.');
			}
		}

		async function uploadRemainingChunks(upload, sessionExists) {
			// A newly generated key does not have a server-side session yet; the first
			// chunk creates it. Existing/resumed sessions are reconciled first.
			if (!sessionExists) {
				showProgress(upload.received, upload.totalChunks, upload.file.size, 'Starting upload…');
			} else {
			// Always reconcile with the server before beginning. The server is the
			// source of truth because a response can be lost after a chunk is written.
				await syncServerState(upload, false);
				showProgress(upload.received, upload.totalChunks, upload.file.size, 'Resuming upload…');
			}

			for (var index = 0; index < upload.totalChunks; index++) {
				if (upload.received.indexOf(index) !== -1) continue;
				await sendChunkWithRetry(upload, index);
				showProgress(upload.received, upload.totalChunks, upload.file.size, 'Uploading video');
				savePending({
					key: upload.key,
					name: upload.file.name,
					size: upload.file.size,
					lastModified: upload.file.lastModified || 0,
					totalChunks: upload.totalChunks,
					bytesReceived: receivedBytes(upload.received, upload.file.size, upload.chunkSize)
				});
			}

			// One final server reconciliation prevents a stale browser state from
			// being treated as complete.
			await syncServerState(upload, true);
			if (upload.received.length >= upload.totalChunks) {
				completeUpload(upload.key, upload.file.name, upload.file.size, upload.totalChunks);
			} else {
				throw new Error('The server reports that the upload is not complete yet. Please resume the upload.');
			}
		}

		async function sendChunkWithRetry(upload, index) {
			var attempts = 0;
			while (attempts < 5) {
				attempts++;
				try {
					var responseData = await sendChunk(upload, index);
					if (!responseData || responseData.success !== true) {
						var rejectedData = responseData && responseData.data ? responseData.data : null;
						var rejected = new Error((rejectedData && rejectedData.message) || 'The server rejected this upload chunk.');
						rejected.retryable = rejectedData ? rejectedData.retryable !== false : true;
						throw rejected;
					}
					var data = responseData.data || {};
					if (data.alreadyReceived || data.complete || responseData.success === true) {
						if (upload.received.indexOf(index) === -1) upload.received.push(index);
					}
					upload.received.sort(function (a, b) { return a - b; });
					return;
				} catch (error) {
					// The request may have reached PHP and the response may have been
					// lost. Re-query the server before retrying so we never re-upload a
					// chunk that was already committed.
					try {
						var status = await requestStatus(upload.key);
						if (status && status.found && Number(status.fileSize) === upload.file.size) {
							mergeReceived(upload.received, status.received || []);
							if (upload.received.indexOf(index) !== -1) return;
							showProgress(upload.received, upload.totalChunks, upload.file.size, 'Server state checked — retrying…');
						}
					} catch (statusError) {
						// Keep the original chunk error; a status check failure is only
						// diagnostic and should not hide it.
					}

					if (error && error.retryable === false) throw error;
					if (attempts >= 5) {
						var detail = error && error.message ? ' ' + error.message : '';
						throw new Error('Chunk ' + (index + 1) + ' failed after 5 attempts.' + detail + ' Server reports ' + upload.received.length + ' of ' + upload.totalChunks + ' chunks received. Your completed chunks are saved; select the same file again to resume.');
					}
					if (progressLabel) progressLabel.textContent = 'Chunk ' + (index + 1) + ' failed — retrying (' + attempts + '/4)…';
					await sleep(Math.min(20000, 1200 * Math.pow(2, attempts - 1)));
				}
			}
		}

		function sendChunk(upload, index) {
			return new Promise(function (resolve, reject) {
				var start = index * upload.chunkSize;
				var end = Math.min(start + upload.chunkSize, upload.file.size);
				var chunk = upload.file.slice(start, end);
				var params = new URLSearchParams();
				params.set('action', 'vidcellar_upload_chunk');
				params.set('nonce', settings.nonce);
				params.set('upload_key', upload.key);
				params.set('file_name', upload.file.name);
				params.set('file_size', String(upload.file.size));
				params.set('last_modified', String(upload.file.lastModified || 0));
				params.set('chunk_index', String(index));
				params.set('total_chunks', String(upload.totalChunks));
				var url = settings.ajaxUrl + (settings.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + params.toString();

				var controller = window.AbortController ? new AbortController() : null;
				var timeout = setTimeout(function () { if (controller) controller.abort(); }, 300000);

				fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/octet-stream', 'X-VidCellar-Chunk': '1' },
					body: chunk,
					signal: controller ? controller.signal : undefined
				})
				.then(function (response) {
					return response.text().then(function (text) {
						var json = null;
						try { json = JSON.parse(text); } catch (e) {}
						if (!response.ok || !json || !json.success) {
							var message = (json && json.data && json.data.message) || ('Server returned HTTP ' + response.status + (text ? ': ' + text.slice(0, 240) : '.'));
							var error = new Error(message);
							error.retryable = json && json.data && json.data.retryable !== false;
							error.status = response.status;
							error.responseText = text;
						reject(error);
							return;
						}
						resolve(json);
					});
				})
				.catch(function (error) {
					if (error && error.name === 'AbortError') {
						error = new Error('The server did not respond within 5 minutes.');
					error.retryable = true;
					}
					reject(error);
				})
				.finally(function () { clearTimeout(timeout); });
			});
		}

		async function requestStatus(key) {
			var body = new FormData();
			body.append('action', 'vidcellar_upload_status');
			body.append('nonce', settings.nonce);
			body.append('upload_key', key);
			var response = await fetch(settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
			var json = await response.json();
			if (!response.ok || !json || !json.success) throw new Error((json && json.data && json.data.message) || 'Could not check the saved upload.');
			return json.data || {};
		}

		async function cancelUpload(key) {
			var body = new FormData();
			body.append('action', 'vidcellar_cancel_upload');
			body.append('nonce', settings.nonce);
			body.append('upload_key', key);
			try { await fetch(settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }); } catch (e) {}
		}

		function completeUpload(key, fileName, fileSize, totalChunks) {
			keyField.value = key;
			nameField.value = fileName;
			fileInput.removeAttribute('name');
			alreadyChunked = true;
			setBusy(false);
			clearPending();
			if (progressBar) progressBar.style.width = '100%';
			if (progressLabel) progressLabel.textContent = 'Upload complete — saving video…';
			if (progressDetail) progressDetail.textContent = formatFileSize(fileSize) + ' of ' + formatFileSize(fileSize) + ' uploaded · ' + totalChunks + ' of ' + totalChunks + ' chunks';
			if (progressBar) progressBar.setAttribute('aria-valuenow', '100');
			if (cancelButton) cancelButton.style.display = 'none';
			form.submit();
		}

		function showProgress(received, totalChunks, fileSize, label) {
			if (!progressWrap) return;
			progressWrap.style.display = 'block';
			var bytes = receivedBytes(received, fileSize, Number(settings.chunkSize));
			var percent = fileSize ? Math.min(100, Math.round((bytes / fileSize) * 100)) : 0;
			if (progressBar) {
				progressBar.style.width = percent + '%';
				progressBar.setAttribute('aria-valuenow', String(percent));
				progressBar.setAttribute('aria-valuemin', '0');
				progressBar.setAttribute('aria-valuemax', '100');
			}
			if (progressLabel) {
				progressLabel.style.color = '';
				progressLabel.textContent = label + ' — ' + percent + '% of file uploaded (' + formatFileSize(fileSize) + ')' ;
			}
			if (progressDetail) {
				progressDetail.textContent = formatFileSize(bytes) + ' of ' + formatFileSize(fileSize) + ' uploaded · ' + received.length + ' of ' + totalChunks + ' chunks';
			}
			if (cancelButton) cancelButton.style.display = 'inline-block';
		}

		function showResumeNotice(pending) {
			if (!progressWrap) return;
			progressWrap.style.display = 'block';
			if (progressLabel) progressLabel.textContent = 'Resume available';
			if (progressDetail) progressDetail.textContent = pending.name + ' · ' + formatFileSize(pending.bytesReceived || 0) + ' of ' + formatFileSize(pending.size || 0) + ' uploaded. Press Add Video to continue.';
		}

		function fail(message) {
			setBusy(false);
			if (progressWrap) progressWrap.style.display = 'block';
			if (progressLabel) { progressLabel.textContent = message; progressLabel.style.color = '#b32d2e'; }
			if (progressDetail) progressDetail.textContent = 'Your completed chunks are preserved. Select the same file and submit again to resume.';
		}

		function setBusy(isBusy) {
			busy = isBusy;
			if (submitButton) submitButton.disabled = isBusy;
			fileInput.disabled = isBusy;
			if (cancelButton) cancelButton.style.display = isBusy ? 'inline-block' : 'none';
		}

		function resetUploadUI() {
			activeUpload = null;
			setBusy(false);
			keyField.value = '';
			nameField.value = '';
			if (fileInput) fileInput.value = '';
			if (progressWrap) progressWrap.style.display = 'none';
		}

		function sameFileIdentity(meta, file) {
			return meta && Number(meta.size) === file.size && String(meta.name) === String(file.name) && (!meta.lastModified || Number(meta.lastModified) === Number(file.lastModified || 0));
		}

		function loadPending() {
			try { var value = localStorage.getItem(storageKey); return value ? JSON.parse(value) : null; } catch (e) { return null; }
		}
		function savePending(value) { try { localStorage.setItem(storageKey, JSON.stringify(value)); } catch (e) {} }
		function clearPending() { try { localStorage.removeItem(storageKey); } catch (e) {} }
		async function syncServerState(upload, requireComplete) {
			var status = await requestStatus(upload.key);
			if (!status || !status.found) throw new Error('The saved upload session could not be found on the server.');
			if (Number(status.fileSize) !== upload.file.size || Number(status.totalChunks) !== upload.totalChunks) {
				throw new Error('The server upload session does not match the selected file.');
			}
			if (Number(status.chunkSize) && Number(status.chunkSize) !== upload.chunkSize) {
				throw new Error('The server chunk size changed. Please start a new upload session.');
			}
			mergeReceived(upload.received, status.received || []);
			if (requireComplete && !status.complete && upload.received.length < upload.totalChunks) {
				return status;
			}
			return status;
		}

		function mergeReceived(target, values) {
			(values || []).forEach(function (value) {
				var index = Number(value);
				if (Number.isInteger(index) && index >= 0 && index < Number.MAX_SAFE_INTEGER && target.indexOf(index) === -1) target.push(index);
			});
			target.sort(function (a, b) { return a - b; });
		}

		function receivedBytes(received, fileSize, chunkSize) {
			var bytes = 0;
			(received || []).forEach(function (index) {
				var offset = Number(index) * chunkSize;
				if (offset < fileSize) bytes += Math.min(chunkSize, fileSize - offset);
			});
			return bytes;
		}
		// Display sizes consistently using binary file-size units, but never
		// promote a normal video to TB. This keeps the progress display tied
		// to the selected file's actual size (e.g. 320 MB of 1.03 GB).
		function formatFileSize(bytes) {
			bytes = Math.max(0, Number(bytes) || 0);
			if (bytes < 1024) return Math.round(bytes) + ' B';
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
			if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(bytes >= 10 * 1024 * 1024 ? 0 : 1) + ' MB';
			return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
		}
		function formatBytes(bytes) {
			return formatFileSize(bytes);
		}
		function sleep(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
		function generateUploadKey() {
			if (window.crypto && window.crypto.randomUUID) return 'u' + window.crypto.randomUUID().replace(/-/g, '');
			return 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 14);
		}
	});
})();
