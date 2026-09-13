@php
    $backgrounds = [
        'radial-gradient(circle at 18% 22%, rgba(245, 158, 11, .34), transparent 28%), radial-gradient(circle at 82% 72%, rgba(14, 165, 233, .30), transparent 32%), linear-gradient(135deg, rgb(15, 23, 42), rgb(30, 41, 59) 48%, rgb(51, 65, 85))',
        'radial-gradient(circle at 78% 18%, rgba(16, 185, 129, .28), transparent 30%), radial-gradient(circle at 16% 78%, rgba(245, 158, 11, .30), transparent 30%), linear-gradient(145deg, rgb(17, 24, 39), rgb(30, 41, 59) 52%, rgb(15, 23, 42))',
        'radial-gradient(circle at 50% 12%, rgba(251, 191, 36, .30), transparent 26%), radial-gradient(circle at 88% 82%, rgba(59, 130, 246, .26), transparent 34%), linear-gradient(120deg, rgb(2, 6, 23), rgb(30, 41, 59) 55%, rgb(51, 65, 85))',
    ];

    $background = $backgrounds[array_rand($backgrounds)];
@endphp

<style>
    .fi-simple-layout {
        background-image: {!! $background !!};
        background-position: center;
        background-repeat: no-repeat;
        background-size: cover;
        position: relative;
        isolation: isolate;
    }

    .fi-simple-layout::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: -1;
        background-image:
            linear-gradient(rgba(255, 255, 255, .025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, .025) 1px, transparent 1px);
        background-size: 48px 48px;
        mask-image: linear-gradient(to bottom, black, transparent 88%);
    }

    .fi-simple-main {
        position: relative;
        border: 1px solid rgba(255, 255, 255, .20);
        background: rgba(255, 255, 255, .88);
        box-shadow: 0 24px 80px rgba(2, 6, 23, .36);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
    }

    .dark .fi-simple-main {
        border-color: rgba(255, 255, 255, .10);
        background: rgba(24, 24, 27, .90);
    }
</style>
