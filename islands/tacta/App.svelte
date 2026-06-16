<script>
  import { onMount } from "svelte";
  import { store, pollOnce, startPolling, stopPolling, loadDeck } from "./lib/state.svelte.js";
  import Lobby from "./components/Lobby.svelte";
  import Game from "./components/Game.svelte";
  import GameOver from "./components/GameOver.svelte";

  onMount(() => {
    // /tacta/{CODE} deep link: pre-fill the code so the lobby can join.
    const m = window.location.pathname.match(/^\/tacta\/([A-Z0-9]{6})$/i);
    if (m) store.code = m[1].toUpperCase();

    (async () => {
      await loadDeck();
      // Reconnect: if our per-game cookie already identifies a seat in this room
      // (e.g. after a reload), resume polling instead of dropping back to the lobby.
      if (store.code && !store.joined) {
        await pollOnce();
        if (store.you) {
          store.joined = true;
          startPolling();
        }
      }
    })();

    return () => stopPolling();
  });
</script>

{#if store.screen === "lobby"}
  <Lobby />
{:else if store.screen === "game"}
  <Game />
{:else}
  <GameOver />
{/if}
