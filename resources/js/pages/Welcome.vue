<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Download, Hammer, PenLine, Wallet } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';

const appName = usePage().props.name;

const steps = [
    {
        icon: PenLine,
        title: 'Describe the image',
        body: 'Write a prompt, pick a size and quality, and add reference images if you like.',
    },
    {
        icon: Wallet,
        title: 'Pay the Price',
        body: "A successful Generation is paid from your Credits: Pixelforge's Provider Cost plus a flat Markup.",
    },
    {
        icon: Download,
        title: 'Download it',
        body: 'When the Generation succeeds, your image is ready to view and download.',
    },
];
</script>

<template>
    <Head title="AI image generation, paid from your Credits" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="border-b">
            <nav
                class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2 font-semibold"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    >
                        <Hammer class="size-5" />
                    </span>
                    {{ appName }}
                </Link>
                <div class="flex items-center gap-2">
                    <Button v-if="$page.props.auth.user" as-child size="sm">
                        <Link :href="dashboard()">Generate</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="ghost" size="sm">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button as-child variant="outline" size="sm">
                            <Link :href="register()">Register</Link>
                        </Button>
                    </template>
                </div>
            </nav>
        </header>

        <main class="flex-1">
            <section
                class="mx-auto flex w-full max-w-3xl flex-col items-center gap-6 px-4 py-16 text-center sm:px-6 sm:py-24"
            >
                <h1
                    class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Generate images from a prompt. Pay only for what you use.
                </h1>
                <p class="max-w-xl text-lg text-balance text-muted-foreground">
                    Top up your Credits, describe what you want, and pay
                    Pixelforge's Provider Cost plus a flat Markup for each
                    Generation.
                </p>
                <Button as-child size="lg">
                    <Link :href="register()">Get started</Link>
                </Button>
            </section>

            <section class="border-t bg-muted/40">
                <div class="mx-auto w-full max-w-5xl px-4 py-14 sm:px-6">
                    <h2 class="text-center text-2xl font-semibold">
                        How it works
                    </h2>
                    <ol class="mt-8 grid gap-6 md:grid-cols-3">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            class="flex flex-col gap-3 rounded-xl border bg-card p-6 text-card-foreground"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-9 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
                                >
                                    {{ index + 1 }}
                                </span>
                                <component
                                    :is="step.icon"
                                    class="size-5 text-muted-foreground"
                                />
                            </div>
                            <h3 class="font-medium">{{ step.title }}</h3>
                            <p class="text-sm text-muted-foreground">
                                {{ step.body }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <section
                class="mx-auto w-full max-w-3xl px-4 py-14 text-center sm:px-6"
            >
                <h2 class="text-2xl font-semibold">
                    A failed Generation costs nothing
                </h2>
                <p class="mt-3 text-muted-foreground">
                    If a Generation fails, your Credits are not spent. You can
                    Try again with the same Generation Parameters.
                </p>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto w-full max-w-5xl px-4 py-6 text-sm text-muted-foreground sm:px-6"
            >
                &copy; {{ new Date().getFullYear() }} {{ appName }}
            </div>
        </footer>
    </div>
</template>
