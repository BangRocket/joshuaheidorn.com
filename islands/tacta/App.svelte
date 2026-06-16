<script>
  import { onMount } from "svelte";
  import { store, joinGame, pollOnce, startPolling, stopPolling, loadDeck } from "./lib/state.svelte.js";
  import Lobby from "./components/Lobby.svelte";
  import Game from "./components/Game.svelte";
  import GameOver from "./components/GameOver.svelte";

  onMount(() => {
    // /tacta/{CODE} deep link: pre-fill the code so the lobby can join.
    const m = window.location.pathname.match(/^\/tacta\/([A-Z0-9]{6})$/i);
    if (m) store.code = m[1].toUpperCase();
    loadDeck();
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
