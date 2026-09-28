/**
 * Copyright (C) 2026  Suhaib Siddiqi
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */
(function ($) {
    'use strict';

    $(function () {
        let frame = null;
        let targetInput = null;
        let targetLabel = null;
        let targetRemove = null;

        function openTrailerPicker(input, label, removeButton) {
            targetInput = $(input);
            targetLabel = $(label);
            targetRemove = $(removeButton || []);
            if (frame) {
                frame.open();
                return;
            }
            frame = wp.media({
                title: 'Select trailer video',
                button: { text: 'Use this trailer' },
                multiple: false,
                library: { type: 'video' }
            });
            frame.on('select', function () {
                const a = frame.state().get('selection').first().toJSON();
                if (!a || !a.id) {
                    return;
                }
                const mime = String(a.mime || '');
                if (mime && mime.indexOf('video/') !== 0) {
                    window.alert('Please select a video file.');
                    return;
                }
                targetInput.val(a.id);
                targetLabel.text(a.filename || a.title || ('Video #' + a.id));
                if (targetRemove && targetRemove.length) {
                    targetRemove.show();
                }
            });
            frame.open();
        }

        $('#vc-select-trailer').on('click', function () {
            openTrailerPicker('#vc_trailer_attachment_id', '#vc-trailer-selected', '#vc-remove-trailer');
        });
        $('#vc-remove-trailer').on('click', function () {
            $('#vc_trailer_attachment_id').val('');
            $('#vc-trailer-selected').text('');
            $(this).hide();
        });

        $('.vc-select-existing-trailer').on('click', function () {
            const form = $(this).closest('form');
            openTrailerPicker(form.find('.vc-trailer-id'), '#' + $(this).data('label'), null);
        });
        $('.vc-clear-existing-trailer').on('click', function () {
            const form = $(this).closest('form');
            form.find('.vc-trailer-id').val('0');
            form.trigger('submit');
        });

        $('.vc-toggle-edit').on('click', function () {
            const button = $(this);
            const panel = button.siblings('.vc-edit-panel');
            const open = panel.is(':visible');
            panel.toggle(!open);
            button.attr('aria-expanded', open ? 'false' : 'true').text(open ? 'Edit' : 'Close');
        });

        $('.vc-cancel-edit').on('click', function () {
            const panel = $(this).closest('.vc-edit-panel');
            panel.hide();
            panel.siblings('.vc-toggle-edit').attr('aria-expanded', 'false').text('Edit');
        });

        $('.vc-select-edit-trailer').on('click', function () {
            const button = $(this);
            const panel = button.closest('.vc-edit-panel');
            const input = panel.find('.vc-edit-trailer-id');
            const label = panel.find('.vc-edit-trailer-label');
            const clear = panel.find('.vc-clear-edit-trailer');
            const picker = wp.media({
                title: 'Select trailer video',
                button: { text: 'Use this trailer' },
                multiple: false,
                library: { type: 'video' }
            });
            picker.on('select', function () {
                const a = picker.state().get('selection').first().toJSON();
                if (!a || !a.id) {
                    return;
                }
                const mime = String(a.mime || '');
                if (mime && mime.indexOf('video/') !== 0) {
                    window.alert('Please select a video file.');
                    return;
                }
                input.val(a.id);
                label.text(a.filename || a.title || ('Video #' + a.id));
                clear.show();
            });
            picker.open();
        });

        $('.vc-clear-edit-trailer').on('click', function () {
            const panel = $(this).closest('.vc-edit-panel');
            panel.find('.vc-edit-trailer-id').val('0');
            panel.find('.vc-edit-trailer-label').text('No trailer selected');
            $(this).hide();
        });

        $(document).on('submit', '.vc-delete-video-form', function (event) {
            if (!window.confirm('Delete this video? This cannot be undone.')) {
                event.preventDefault();
            }
        });
    });
})(jQuery);
