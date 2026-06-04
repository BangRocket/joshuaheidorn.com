<script>
    let { phrases = [], typeMs = 60, deleteMs = 35, holdMs = 1800, gapMs = 400 } = $props();

    let text = $state(phrases[0] ?? "");
    let phraseIdx = $state(0);
    let phase = $state("hold"); // hold | deleting | typing

    $effect(() => {
        if (!phrases.length) return;
        const current = phrases[phraseIdx];
        let timer;

        if (phase === "hold") {
            timer = setTimeout(() => (phase = "deleting"), holdMs);
        } else if (phase === "deleting") {
            if (text.length === 0) {
                timer = setTimeout(() => {
                    phraseIdx = (phraseIdx + 1) % phrases.length;
                    phase = "typing";
                }, gapMs);
            } else {
                timer = setTimeout(() => (text = text.slice(0, -1)), deleteMs);
            }
        } else if (phase === "typing") {
            if (text.length === current.length) {
                phase = "hold";
            } else {
                timer = setTimeout(() => (text = current.slice(0, text.length + 1)), typeMs);
            }
        }

        return () => clearTimeout(timer);
    });
</script>

{#if phrases.length}
    <span class="typewriter" aria-live="polite" aria-label={phrases[phraseIdx]}>
        <span>{text}</span>
        <span class="typewriter-caret" aria-hidden="true">|</span>
    </span>
{/if}
