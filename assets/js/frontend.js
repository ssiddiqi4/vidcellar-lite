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

(function () {
  'use strict';

  if (typeof vidCellar === 'undefined' || !vidCellar.restUrl) {
    return;
  }

  function restPost(path, body) {
    return fetch(vidCellar.restUrl + path, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': vidCellar.nonce,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams(body),
    }).then(async (resp) => {
      const data = await resp.json();
      if (!resp.ok) throw new Error(data.message || 'Something went wrong');
      return data;
    });
  }

  function initVideoRatings() {
    document.querySelectorAll('.vc-video-rating').forEach((ratingBox) => {
      if (ratingBox.dataset.initialized === '1') return;
      ratingBox.dataset.initialized = '1';

      const buttons = ratingBox.querySelectorAll('.vc-rating-star');
      const message = ratingBox.querySelector('.vc-rating-message');
      const summary = ratingBox.querySelector('.vc-rating-summary');
      const count = ratingBox.querySelector('.vc-rating-count');
      const videoId = ratingBox.dataset.videoId;
      const guestToken = ratingBox.dataset.guestToken || '';

      buttons.forEach((button) => {
        button.addEventListener('click', async () => {
          const value = Number(button.dataset.rating);
          if (!value || value < 1 || value > 5) return;

          buttons.forEach((b) => { b.disabled = true; });
          if (message) message.textContent = 'Saving your rating…';

          try {
            const data = await restPost('/rate-video', {
              video_id: videoId,
              rating: String(value),
              guest_token: guestToken,
            });

            buttons.forEach((b) => {
              const n = Number(b.dataset.rating);
              const selected = n === value;
              b.classList.toggle('selected', selected);
              b.setAttribute('aria-pressed', selected ? 'true' : 'false');
              b.textContent = selected ? '★' : '☆';
            });

            if (summary) {
              const stars = summary.querySelector('.vc-stars');
              if (stars) {
                const rounded = Math.round(Number(data.average || 0));
                stars.textContent = '★★★★★'.split('').map((_, i) => i + 1 <= rounded ? '★' : '☆').join('');
                stars.setAttribute('aria-label', 'Rating ' + Number(data.average || 0).toFixed(1) + ' out of 5');
              }
              const number = summary.querySelector('.vc-rating-number');
              if (number) number.textContent = Number(data.average || 0).toFixed(1) + '/5';
            }
            if (count) {
              count.textContent = String(data.count || 0) + ' rating' + (Number(data.count || 0) === 1 ? '' : 's');
            }
            if (message) message.textContent = 'Your rating has been saved.';
          } catch (err) {
            if (message) message.textContent = err.message || 'Your rating could not be saved.';
          } finally {
            buttons.forEach((b) => { b.disabled = false; });
          }
        });
      });
    });
  }

  // Lightweight YouTube-style HTML5 player. It keeps the actual video URL
  // protected by the existing stream endpoint while providing familiar
  // controls without loading a third-party player library.
  function initVideoPlayers() {
    document.querySelectorAll('.vc-player').forEach((player) => {
      if (player.dataset.initialized === '1') return;
      player.dataset.initialized = '1';

      const video = player.querySelector('.vc-video-element');
      const adContainer = player.querySelector('.vc-ad-container');
      const adVideo = player.querySelector('.vc-ad-element');
      const adCountdown = player.querySelector('.vc-ad-countdown');
      let advertisements = [];
      try { advertisements = JSON.parse(player.dataset.advertisements || '[]'); } catch (e) { advertisements = []; }
      advertisements = Array.isArray(advertisements) ? advertisements.filter((ad) => ad && ad.url) : [];
      const adIntervalMinutes = Math.max(1, Math.min(60, Number(player.dataset.adInterval || 0)));
      const adIntervalSeconds = adIntervalMinutes * 60;
      let adPlaying = false;
      let adTimer = null;
      let adBreaksPlayed = 0;
      let nextAdAt = adIntervalSeconds > 0 ? adIntervalSeconds : Infinity;
      let savedMovieTime = 0;
      let selectedAd = null;
      const centerPlay = player.querySelector('.vc-center-play');
      const playBtn = player.querySelector('.vc-play');
      const volumeBtn = player.querySelector('.vc-volume');
      const volumeSlider = player.querySelector('.vc-volume-slider');
      const settingsBtn = player.querySelector('.vc-settings');
      const settingsMenu = player.querySelector('.vc-player-settings');
      const speedSetting = player.querySelector('.vc-speed-setting');
      const speedSelect = player.querySelector('.vc-speed-select');
      const pipSetting = player.querySelector('[data-setting="pip"]');
      const fullscreenBtn = player.querySelector('.vc-fullscreen');
      const progress = player.querySelector('.vc-progress');
      const buffered = player.querySelector('.vc-buffered');
      const currentTime = player.querySelector('.vc-current-time');
      const duration = player.querySelector('.vc-duration');
      const loading = player.querySelector('.vc-player-loading');
      // Buffering is intentionally invisible; the browser may buffer in the background.
      if (loading) { loading.hidden = true; loading.style.display = 'none'; }
      const errorBox = player.querySelector('.vc-player-error');
      const controls = player.querySelector('.vc-controls');
      let controlsTimer;

      const formatTime = (seconds) => {
        if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
        const total = Math.floor(seconds);
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const sec = total % 60;
        return h > 0
          ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
          : `${m}:${String(sec).padStart(2, '0')}`;
      };

      const updateBuffered = () => {
        if (!buffered || !Number.isFinite(video.duration) || video.duration <= 0 || !video.buffered.length) return;
        let end = 0;
        try { end = video.buffered.end(video.buffered.length - 1); } catch (e) { return; }
        buffered.style.width = Math.max(0, Math.min(100, (end / video.duration) * 100)) + '%';
      };

      const showControls = () => {
        player.classList.add('vc-controls-visible');
        clearTimeout(controlsTimer);
      };

      const closeSettingsIfOpen = () => {
        if (!settingsMenu) return;
        settingsMenu.hidden = true;
        settingsMenu.style.display = '';
        if (settingsBtn) settingsBtn.setAttribute('aria-expanded', 'false');
      };

      const hideControls = () => {
        if (!video.paused && !video.ended) {
          player.classList.remove('vc-controls-visible');
          closeSettingsIfOpen();
        }
      };

      const setPlayingUi = (playing) => {
        const isPlaying = !!playing && !video.paused && !video.ended;
        playBtn.innerHTML = '<span class="vc-control-icon" aria-hidden="true">' + (isPlaying ? '❚❚' : '▶') + '</span>';
        playBtn.setAttribute('aria-label', isPlaying ? 'Pause' : 'Play');
        // The large center play button must disappear as soon as playback
        // actually starts, including during network buffering.
        centerPlay.hidden = isPlaying || adPlaying;
        player.classList.toggle('vc-playing', isPlaying);
        if (isPlaying) {
          player.classList.remove('vc-controls-visible');
        } else {
          player.classList.add('vc-controls-visible');
        }
      };

      const syncPlayerUi = () => {
        const isPlaying = !video.paused && !video.ended;
        setPlayingUi(isPlaying);
        // A buffering event can occur while the movie is still playing. Do
        // not bring the large Play button back; only show the spinner.
        if (isPlaying && video.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA && !video.seeking) {
          loading.hidden = true;
          player.classList.remove('vc-buffering');
        }
      };

      const finishAdvertisement = () => {
        if (!adPlaying) return;
        adPlaying = false;
        clearInterval(adTimer);
        adTimer = null;
        if (adVideo) {
          adVideo.pause();
          adVideo.removeAttribute('src');
          adVideo.load();
        }
        if (adContainer) adContainer.hidden = true;
        if (adCountdown) adCountdown.textContent = '';
        video.hidden = false;
        centerPlay.hidden = false;

        // Schedule the next break from the point at which the movie resumes.
        // This means ad time itself never counts toward the movie interval.
        if (adIntervalSeconds > 0) {
          nextAdAt = Math.max(0, savedMovieTime) + adIntervalSeconds;
        }
        video.currentTime = savedMovieTime;
        video.play().catch(() => {
          errorBox.hidden = false;
          errorBox.textContent = 'Advertisement finished. Press Play to continue the movie.';
        });
      };

      const chooseAdvertisement = () => {
        if (!advertisements.length) return null;
        // Rotate without immediately repeating the same advertisement when
        // multiple active advertisements are available.
        if (advertisements.length === 1) return advertisements[0];
        const candidates = advertisements.filter((ad) => ad.url !== (selectedAd && selectedAd.url));
        return candidates[Math.floor(Math.random() * candidates.length)] || advertisements[0];
      };

      const playAdvertisement = async (isInitial = false) => {
        if (!advertisements.length || !adVideo || adPlaying) return false;
        selectedAd = chooseAdvertisement();
        if (!selectedAd || !selectedAd.url) return false;

        adPlaying = true;
        savedMovieTime = Number.isFinite(video.currentTime) ? video.currentTime : 0;
        video.pause();
        video.hidden = true;
        centerPlay.hidden = true;
        if (adContainer) adContainer.hidden = false;
        adVideo.src = selectedAd.url;
        adVideo.currentTime = 0;
        try {
          await adVideo.play();
          const updateCountdown = () => {
            const remaining = Math.max(0, Math.ceil((adVideo.duration || Number(selectedAd.duration) || 15) - adVideo.currentTime));
            if (adCountdown) adCountdown.textContent = 'Advertisement • ' + remaining + 's';
          };
          updateCountdown();
          clearInterval(adTimer);
          adTimer = setInterval(updateCountdown, 250);
          if (!isInitial) adBreaksPlayed++;
          return true;
        } catch (err) {
          finishAdvertisement();
          return false;
        }
      };

      const togglePlay = async () => {
        try {
          if (adPlaying) return;
          if (video.paused || video.ended) {
            if (video.ended) {
              video.currentTime = 0;
              nextAdAt = adIntervalSeconds > 0 ? adIntervalSeconds : Infinity;
            }
            if (video.currentTime === 0 && advertisements.length && adBreaksPlayed === 0) {
              const started = await playAdvertisement(true);
              if (started) return;
            }
            await video.play();
          } else {
            video.pause();
          }
        } catch (err) {
          errorBox.hidden = false;
          errorBox.textContent = 'Unable to start playback. Please refresh the page and try again.';
        }
      };

      centerPlay.addEventListener('click', togglePlay);
      playBtn.addEventListener('click', togglePlay);
      video.addEventListener('click', togglePlay);

      volumeBtn.addEventListener('click', () => {
        video.muted = !video.muted;
        volumeBtn.innerHTML = '<span class="vc-control-icon" aria-hidden="true">' + (video.muted || video.volume === 0 ? '🔇' : '🔊') + '</span>';
        volumeBtn.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute');
      });

      if (volumeSlider) {
        volumeSlider.value = Math.round(video.volume * 100);
        volumeSlider.addEventListener('input', () => {
          const level = Math.max(0, Math.min(100, Number(volumeSlider.value))) / 100;
          video.volume = level;
          video.muted = level === 0;
        });
      }

      const closeSettings = () => {
        closeSettingsIfOpen();
      };

      const toggleSettings = (event) => {
        if (event) { event.preventDefault(); event.stopPropagation(); }
        if (!settingsMenu) return;
        const opening = settingsMenu.hasAttribute('hidden');
        if (opening) {
          settingsMenu.removeAttribute('hidden');
          settingsMenu.style.display = 'block';
        } else {
          settingsMenu.setAttribute('hidden', 'hidden');
          settingsMenu.style.display = '';
        }
        if (settingsBtn) settingsBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) showControls();
      };

      if (settingsBtn) settingsBtn.addEventListener('click', toggleSettings);

      if (speedSelect) {
        speedSelect.addEventListener('change', (event) => {
          const speed = Number(event.target.value);
          if (Number.isFinite(speed) && speed > 0) {
            video.playbackRate = speed;
          }
          showControls();
        });
        speedSelect.addEventListener('click', (event) => {
          event.stopPropagation();
        });
      }

      if (pipSetting) pipSetting.addEventListener('click', async () => {
        try {
          if (document.pictureInPictureElement) {
            await document.exitPictureInPicture();
          } else if (document.pictureInPictureEnabled && video.requestPictureInPicture) {
            await video.requestPictureInPicture();
          } else if (video.webkitSetPresentationMode) {
            video.webkitSetPresentationMode(video.webkitPresentationMode === 'picture-in-picture' ? 'inline' : 'picture-in-picture');
          } else {
            throw new Error('Picture-in-picture is not supported by this browser.');
          }
        } catch (err) {
          if (errorBox) {
            errorBox.hidden = false;
            errorBox.textContent = err.message || 'Picture-in-picture is not available.';
          }
        } finally {
          closeSettings();
        }
      });

      document.addEventListener('click', (event) => {
        if (!settingsMenu || settingsMenu.hidden) return;
        const target = event.target;
        if (settingsMenu.contains(target) || (settingsBtn && settingsBtn.contains(target))) return;
        closeSettings();
      });

      fullscreenBtn.addEventListener('click', async () => {
        try {
          if (document.fullscreenElement === player) {
            await document.exitFullscreen();
          } else if (player.requestFullscreen) {
            await player.requestFullscreen();
          } else if (video.webkitEnterFullscreen) {
            video.webkitEnterFullscreen();
          }
        } catch (err) {}
      });

      progress.addEventListener('input', () => {
        if (!Number.isFinite(video.duration) || video.duration <= 0) return;
        video.currentTime = (Number(progress.value) / 1000) * video.duration;
        showControls();
      });

      video.addEventListener('loadedmetadata', () => {
        duration.textContent = formatTime(video.duration);
        currentTime.textContent = formatTime(video.currentTime);
        errorBox.hidden = true;
      });

      video.addEventListener('timeupdate', () => {
        syncPlayerUi();
        currentTime.textContent = formatTime(video.currentTime);
        updateBuffered();
        if (Number.isFinite(video.duration) && video.duration > 0) {
          progress.value = String(Math.round((video.currentTime / video.duration) * 1000));
        }

        // Recurring mid-roll breaks follow actual movie playback time. A
        // viewer cannot trigger an ad by editing a URL/query parameter.
        if (!adPlaying && advertisements.length && adIntervalSeconds > 0 &&
            video.currentTime >= nextAdAt && !video.ended && !video.paused) {
          playAdvertisement(false);
        }
      });

      video.addEventListener('play', () => syncPlayerUi());
      video.addEventListener('playing', () => syncPlayerUi());
      video.addEventListener('pause', () => syncPlayerUi());
      video.addEventListener('ended', () => {
        setPlayingUi(false);
        progress.value = '1000';
        player.classList.add('vc-controls-visible');
      });

      if (adVideo) {
        adVideo.addEventListener('ended', finishAdvertisement);
        adVideo.addEventListener('error', finishAdvertisement);
        adVideo.addEventListener('loadedmetadata', () => {
          if (adVideo.duration > 15.01) finishAdvertisement();
        });
      }

      video.addEventListener('waiting', () => {
        syncPlayerUi();
        // Do not pause the media element here. The browser continues its
        // background network buffering and will resume as soon as enough
        // media is available. The overlay is visual only.
        loading.hidden = true;
        loading.style.display = 'none';
        player.classList.add('vc-buffering');
      });
      video.addEventListener('stalled', () => {
        loading.hidden = true;
        loading.style.display = 'none';
        player.classList.add('vc-buffering');
      });
      video.addEventListener('progress', updateBuffered);
      video.addEventListener('loadeddata', updateBuffered);
      video.addEventListener('canplay', () => {
        updateBuffered();
        syncPlayerUi();
        loading.hidden = true;
        loading.style.display = 'none';
        player.classList.remove('vc-buffering');
      });
      video.addEventListener('playing', () => {
        updateBuffered();
        loading.hidden = true;
        loading.style.display = 'none';
        player.classList.remove('vc-buffering');
        errorBox.hidden = true;
        syncPlayerUi();
      });
      video.addEventListener('error', () => {
        loading.hidden = true;
        loading.style.display = 'none';
        player.classList.remove('vc-buffering');
        errorBox.hidden = false;
        const mediaError = video.error;
        if (mediaError && mediaError.code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED) {
          errorBox.textContent = 'This video format is not supported by your browser.';
        } else {
          errorBox.textContent = 'Unable to play this video. Please refresh the page and try again.';
        }
      });

      player.addEventListener('mousemove', showControls);
      player.addEventListener('touchstart', showControls, {passive: true});
      player.addEventListener('mouseleave', () => {
        if (!video.paused && !video.ended) {
          hideControls();
        } else {
          showControls();
        }
      });
      player.addEventListener('mouseenter', showControls);
      player.addEventListener('contextmenu', (event) => event.preventDefault());

      video.addEventListener('volumechange', () => {
        volumeBtn.innerHTML = '<span class="vc-control-icon" aria-hidden="true">' + (video.muted || video.volume === 0 ? '🔇' : (video.volume < 0.5 ? '🔉' : '🔊')) + '</span>';
        if (volumeSlider) volumeSlider.value = video.muted ? 0 : Math.round(video.volume * 100);
      });

      // Establish the correct initial state even if the browser began loading
      // the media before the event listeners were attached.
      syncPlayerUi();

      // Start with controls visible so the player is understandable before
      // the first interaction, then let them fade while playing.
      showControls();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initVideoRatings();
      initVideoPlayers();
    });
  } else {
    initVideoRatings();
    initVideoPlayers();
  }


})();
